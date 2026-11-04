=== Beltoft EU VAT for WooCommerce ===
Contributors: beltoft
Tags: woocommerce, vat, eu vat, vies, reverse charge
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An optional EU VAT number field at checkout, checked with VIES. A valid number from another EU country removes VAT (reverse charge).

== Description ==

Adds an optional "EU VAT number" field to the billing form of the classic WooCommerce checkout and to the billing address in My Account.

* An empty field is a normal sale and VAT is charged.
* A number from your own country is a domestic sale: VAT is charged. The number is still checked with VIES, so a mistyped one is refused (setting, on by default). An unreachable VIES never blocks a domestic number.
* A number from another EU country must match the billing country. VIES is asked and a valid number removes VAT (reverse charge). An invalid number blocks the checkout.
* If VIES does not answer, the order proceeds with VAT charged and is flagged for review (or the checkout is blocked, by setting). The shop admin gets one email per flagged order. If the same number was confirmed by VIES within the grace period (30 days), it is accepted instead as reverse charge and the order records the date and consultation number of that confirmation.
* The VAT number is shown with the billing address ("VAT DK12345678") in order emails, on the order received page, in My Account and on the admin order screen, and in anything that uses the formatted billing address.
* Orders that are reverse charged carry the note "Reverse charge: VAT is to be accounted for by the customer (Article 196, Council Directive 2006/112/EC)." in order emails and order views.
* The VIES consultation number, the registered name and the date are stored on the order.
* A valid answer is cached for 72 hours. An invalid answer is remembered for 10 minutes and an unreachable one for 60 seconds.

= Fast checkout =

* The checkout refresh never calls VIES. It reads the cache; with nothing cached, VAT is charged with a note until the number has been checked.
* The number is checked by a small request of its own right after typing (on leaving the field, or 600 ms after the typing stops). The result shows under the field, then the order summary refreshes once.
* Without JavaScript the number is checked when the order is placed.
* My Account > Addresses has the same check, and shows when the saved number was confirmed and the VIES reference.
* Every night, about 01:00, the saved numbers of customers with an active subscription or a paid order in the last 12 months are checked again, 20 at a time with a pause between calls (Action Scheduler, or WP-Cron without it). The result is kept on the account. If a number that was valid is no longer, the customer is marked, the shop admin gets one email and the next order is charged VAT. If VIES does not answer, nothing changes and it is tried again the next night.

Settings are under WooCommerce > Settings > Tax > EU VAT: seller country, your own VAT number (sent to VIES as the requester), field label, behaviour when VIES does not answer, and the review email.

The block checkout is not supported.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/` and activate it. WooCommerce must be active.
2. Open WooCommerce > Settings > Tax > EU VAT and enter your own VAT number.

== Frequently Asked Questions ==

= Where is the number stored? =

User meta and order meta `billing_eu_vat_number` (order meta with a leading underscore), like any billing field. The check evidence is stored in `_beltoft_eu_vat_*` order meta.

= Can I add a per-country format check? =

Yes, with the `beltoft_eu_vat_local_check` filter. It receives null, the country, the number without prefix and the number as typed, and returns true, false or null. Portugal's NIF check digit is built in as an example. Other filters: `beltoft_eu_vat_seller_country`, `beltoft_eu_vat_own_number`, `beltoft_eu_vat_label`, `beltoft_eu_vat_admin_email`, `beltoft_eu_vat_destination`, `beltoft_eu_vat_cache_ttl` (default 3 days), `beltoft_eu_vat_grace_days` (default 30, 0 is off), `beltoft_eu_vat_check_rate_limit`, `beltoft_eu_vat_recheck_batch_size`, `beltoft_eu_vat_recheck_pause_ms`, `beltoft_eu_vat_recheck_batch_interval`, `beltoft_eu_vat_recheck_hour`, `beltoft_eu_vat_recheck_eligible`, `beltoft_eu_vat_toggle_field`, `beltoft_eu_vat_address_label`, `beltoft_eu_vat_reverse_charge_text`.

== Changelog ==

= 0.3.0 =
* The checkout refresh never calls VIES. A valid answer is cached for 72 hours.
* Inline check right after typing, in its own rate limited wc-ajax request, with a status line under the field and a "Check again" link after an error.
* Nightly re-check of saved numbers in batches, stored as evidence on the account. A number that is no longer valid marks the customer and mails the admin once.
* Grace: while VIES is down, a number confirmed within 30 days (setting, filter `beltoft_eu_vat_grace_days`) is accepted as reverse charge, recorded on the order and not flagged.
* My Account > Addresses: the same check, and the stored status with date and reference.

= 0.2.1 =
* A VIES outage answer (userError such as MS_UNAVAILABLE) counts as unreachable, not invalid.
* Invalid answers are remembered for 10 minutes and unreachable ones for 60 seconds; over-long numbers are refused without asking VIES. Filters `beltoft_eu_vat_invalid_cache_ttl`, `beltoft_eu_vat_unreachable_cache_ttl`, `beltoft_eu_vat_max_number_length`.
* A checkout without the VAT field never falls back to the account's number, and an exempt order without a number is refused.

= 0.2.0 =
* Domestic VAT numbers are checked with VIES too (setting "Check domestic VAT numbers with VIES", on by default). Invalid blocks the checkout; unreachable proceeds.
* The VAT number is part of the formatted billing address (emails, order pages, My Account, admin, invoices).
* Reverse charge note on exempt orders in emails and order views.

= 0.1.0 =
* First release.
