<?php
/**
 * The nightly re-check of saved VAT numbers.
 *
 * @package BeltoftEuVat
 */

namespace BeltoftEuVat;

defined( 'ABSPATH' ) || exit;

/**
 * Once a night (about 01:00 site time) the saved VAT numbers of customers with an active
 * subscription or a paid order in the last 12 months are asked of VIES again, in small batches
 * spread through the night, with a short pause between calls.
 *
 * Scheduled with Action Scheduler (group beltoft-eu-vat), which WooCommerce ships; WP-Cron is
 * the fallback when it is not there.
 *
 * The answer is stored on the account as evidence (status, date, VIES reference, registered
 * name). A number that was valid and now is not marks the customer and mails the admin once;
 * its next order is charged VAT. An unreachable VIES changes nothing: the number is tried
 * again the next night.
 */
final class Recheck {

	public const START = 'beltoft_eu_vat_recheck_start';
	public const BATCH = 'beltoft_eu_vat_recheck_batch';
	public const QUEUE = 'beltoft_eu_vat_recheck_queue';
	public const GROUP = 'beltoft-eu-vat';

	/** Unreachable answers in a row after which the night's run stops. */
	private const GIVE_UP = 3;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( self::START, array( __CLASS__, 'start' ) );
		add_action( self::BATCH, array( __CLASS__, 'run_batch' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ), 20 );
	}

	/**
	 * Whether Action Scheduler is there.
	 */
	public static function uses_action_scheduler(): bool {
		return function_exists( 'as_schedule_recurring_action' ) && function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_single_action' );
	}

	/**
	 * Makes sure the nightly start is scheduled.
	 */
	public static function schedule(): void {
		$first = self::next_start();

		if ( self::uses_action_scheduler() ) {
			if ( ! as_has_scheduled_action( self::START, array(), self::GROUP ) ) {
				as_schedule_recurring_action( $first, DAY_IN_SECONDS, self::START, array(), self::GROUP );
			}

			return;
		}

		if ( ! wp_next_scheduled( self::START ) ) {
			wp_schedule_event( $first, 'daily', self::START );
		}
	}

	/**
	 * Removes the schedule. Used on deactivation and uninstall.
	 */
	public static function unschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::START, array(), self::GROUP );
			as_unschedule_all_actions( self::BATCH, array(), self::GROUP );
		}

		wp_clear_scheduled_hook( self::START );
		wp_clear_scheduled_hook( self::BATCH );
		delete_option( self::QUEUE );
	}

	/**
	 * The next time the night's run starts: 01:00 site time (filter `beltoft_eu_vat_recheck_hour`).
	 */
	public static function next_start(): int {
		/**
		 * Filters the hour (0-23, site time) the nightly re-check starts.
		 *
		 * @param int $hour Hour.
		 */
		$hour = min( 23, max( 0, (int) apply_filters( 'beltoft_eu_vat_recheck_hour', 1 ) ) );
		$now  = new \DateTimeImmutable( 'now', wp_timezone() );
		$next = $now->setTime( $hour, 0, 0 );

		if ( $next <= $now ) {
			$next = $next->modify( '+1 day' );
		}

		return $next->getTimestamp();
	}

	/**
	 * The nightly start: queues the accounts that hold a number and schedules the first batch.
	 *
	 * @return int Accounts queued.
	 */
	public static function start(): int {
		$ids = get_users(
			array(
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- A nightly job, not a page request.
					'relation' => 'OR',
					array(
						'key'     => Field::KEY,
						'value'   => '',
						'compare' => '!=',
					),
					array(
						'key'     => Evidence::LEGACY_USER_META,
						'value'   => '',
						'compare' => '!=',
					),
				),
				'fields'     => 'ID',
				'orderby'    => 'ID',
				'number'     => -1,
			)
		);

		$ids = array_values( array_map( 'intval', $ids ) );

		update_option( self::QUEUE, $ids, false );

		if ( $ids ) {
			self::schedule_batch( time() );
		}

		return count( $ids );
	}

	/**
	 * One batch: the next accounts in the queue, one VIES call each, with a pause between.
	 *
	 * @return int VIES calls made.
	 */
	public static function run_batch(): int {
		$queue = get_option( self::QUEUE, array() );
		$queue = is_array( $queue ) ? $queue : array();

		/**
		 * Filters how many numbers one batch asks VIES about.
		 *
		 * @param int $size Default 20.
		 */
		$size    = max( 1, (int) apply_filters( 'beltoft_eu_vat_recheck_batch_size', 20 ) );
		$calls   = 0;
		$in_a_row = 0;

		while ( $queue && $calls < $size ) {
			$user_id = (int) array_shift( $queue );

			if ( ! self::eligible( $user_id ) ) {
				continue;
			}

			if ( $calls > 0 ) {
				self::pause();
			}

			++$calls;

			if ( 'unreachable' === self::check_user( $user_id ) ) {
				++$in_a_row;
			} else {
				$in_a_row = 0;
			}

			if ( $in_a_row >= self::GIVE_UP ) {
				// VIES is down: stop, the numbers are tried again tomorrow night.
				$queue = array();
			}
		}

		if ( $queue ) {
			update_option( self::QUEUE, $queue, false );

			/**
			 * Filters the seconds between batches.
			 *
			 * @param int $seconds Default 300.
			 */
			self::schedule_batch( time() + max( 1, (int) apply_filters( 'beltoft_eu_vat_recheck_batch_interval', 5 * MINUTE_IN_SECONDS ) ) );
		} else {
			delete_option( self::QUEUE );
		}

		return $calls;
	}

	/**
	 * Whether an account's number is worth checking: an active subscription, or a paid order in the last 12 months.
	 *
	 * @param int $user_id User id.
	 */
	public static function eligible( int $user_id ): bool {
		$eligible = false;

		if ( $user_id > 0 && '' !== Evidence::stored_number( $user_id ) ) {
			if ( function_exists( 'wcs_user_has_subscription' ) && wcs_user_has_subscription( $user_id, '', 'active' ) ) {
				$eligible = true;
			} else {
				$orders = wc_get_orders(
					array(
						'customer_id'  => $user_id,
						'status'       => wc_get_is_paid_statuses(),
						'date_created' => '>' . ( time() - YEAR_IN_SECONDS ),
						'limit'        => 1,
						'return'       => 'ids',
					)
				);

				$eligible = ! empty( $orders );
			}
		}

		/**
		 * Filters whether an account's VAT number is re-checked tonight.
		 *
		 * @param bool $eligible Default: active subscription or paid order within 12 months.
		 * @param int  $user_id  User id.
		 */
		return (bool) apply_filters( 'beltoft_eu_vat_recheck_eligible', $eligible, $user_id );
	}

	/**
	 * Asks VIES (not the cache) about an account's number and records what it said.
	 *
	 * @param int $user_id User id.
	 * @return string valid, invalid, unreachable or skipped.
	 */
	public static function check_user( int $user_id ): string {
		$number = Settings::normalise( Evidence::stored_number( $user_id ) );
		$prefix = substr( $number, 0, 2 );
		$prefix = 'EL' === $prefix ? 'GR' : $prefix;

		if ( '' === $number || ! Countries::is_eu( $prefix ) ) {
			return 'skipped';
		}

		$decision = Validator::decide( $number, $prefix, true, true );

		if ( 'unverified' === $decision->status ) {
			return 'unreachable';
		}

		$valid = 'valid' === $decision->status || ( 'domestic' === $decision->status && $decision->confirmed );

		if ( $valid ) {
			update_user_meta( $user_id, Evidence::STATUS, 'valid' === $decision->status ? 'valid' : 'domestic' );
			update_user_meta( $user_id, Evidence::REQUEST_ID, $decision->request_identifier );
			update_user_meta( $user_id, Evidence::NAME, $decision->registered_name );
			update_user_meta( $user_id, Evidence::CHECKED_AT, gmdate( 'c', $decision->checked_at ) );
			update_user_meta( $user_id, Evidence::RECHECKED_AT, gmdate( 'c' ) );
			delete_user_meta( $user_id, Evidence::FLAG );
			delete_user_meta( $user_id, Evidence::MAILED );

			return 'valid';
		}

		if ( 'invalid' === $decision->status ) {
			self::mark_invalid( $user_id, $number );

			return 'invalid';
		}

		return 'skipped';
	}

	/**
	 * A number VIES no longer knows: marks the account and mails the admin once, if the number was valid before.
	 *
	 * @param int    $user_id User id.
	 * @param string $number  Normalised number.
	 */
	private static function mark_invalid( int $user_id, string $number ): void {
		$was_valid = in_array( (string) get_user_meta( $user_id, Evidence::STATUS, true ), array( 'valid', 'domestic' ), true );

		update_user_meta( $user_id, Evidence::STATUS, 'invalid' );
		update_user_meta( $user_id, Evidence::REQUEST_ID, '' );
		update_user_meta( $user_id, Evidence::NAME, '' );
		update_user_meta( $user_id, Evidence::CHECKED_AT, gmdate( 'c' ) );
		update_user_meta( $user_id, Evidence::RECHECKED_AT, gmdate( 'c' ) );
		update_user_meta( $user_id, Evidence::FLAG, 'invalid' );

		if ( ! $was_valid || (string) get_user_meta( $user_id, Evidence::MAILED, true ) === $number ) {
			return;
		}

		$user = get_userdata( $user_id );

		wp_mail(
			Settings::admin_email(),
			sprintf(
				/* translators: %s: customer name or login */
				__( 'VAT number no longer valid for %s', 'beltoft-eu-vat' ),
				$user ? $user->display_name : (string) $user_id
			),
			sprintf(
				/* translators: 1: VAT number, 2: customer email, 3: link to the customer */
				__( "The nightly check found that VIES no longer confirms VAT number %1\$s, which was valid before.\n\nCustomer: %2\$s\nOpen the customer: %3\$s\n\nThe customer's next order is charged VAT. If the number should still be valid, ask the customer.", 'beltoft-eu-vat' ),
				$number,
				$user ? $user->user_email : '',
				admin_url( 'user-edit.php?user_id=' . $user_id )
			)
		);

		update_user_meta( $user_id, Evidence::MAILED, $number );
	}

	/**
	 * Sleeps between two VIES calls so that VIES is not hammered.
	 */
	private static function pause(): void {
		/**
		 * Filters the pause between two VIES calls in the nightly re-check, in milliseconds.
		 *
		 * @param int $ms Default 1500.
		 */
		$ms = (int) apply_filters( 'beltoft_eu_vat_recheck_pause_ms', 1500 );

		if ( $ms > 0 ) {
			usleep( $ms * 1000 );
		}
	}

	/**
	 * Schedules the next batch.
	 *
	 * @param int $when Unix time.
	 */
	private static function schedule_batch( int $when ): void {
		if ( self::uses_action_scheduler() ) {
			as_schedule_single_action( $when, self::BATCH, array(), self::GROUP );

			return;
		}

		wp_schedule_single_event( $when, self::BATCH );
	}
}
