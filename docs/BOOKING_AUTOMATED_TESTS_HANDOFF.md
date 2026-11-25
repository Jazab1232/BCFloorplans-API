# Backend Automated Test Suite — QA & Engineering Handoff Guide

This document is a complete handoff guide for the **34 automated PHPUnit tests** covering booking, appointments, time slots, overlaps, breaks, work hours, travel rates, twilight windows, Google Calendar sync, and Admin vs. Agent role permissions in `bcf-api`.

---

## 1. Quick Start: Running & Monitoring the Automated Tests

### Prerequisites
- Working local Laravel environment for `bcf-api` with PHP 8.2+ and Composer dependencies installed (`vendor/`).
- Default `phpunit.xml` runs against **SQLite `:memory:`** (`DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`) with `RefreshDatabase`, `Queue::fake()`, `Mail::fake()`, and mocked `GoogleCalendarService`, so it will **not** touch your live/dev database or send real emails/Google Calendar requests.

### Run All 4 Booking Test Suites
```bash
cd bcf-api
php artisan test \
  tests/Feature/BookingOverlapAndAvailabilityTest.php \
  tests/Feature/BookingLifecycleAndBreaksTest.php \
  tests/Feature/GoogleCalendarSyncAndAvailabilityTest.php \
  tests/Feature/BookingAuthorizationAndTwilightTest.php
```

### Run a Single Suite or Single Use Case
```bash
# Run one suite
php artisan test --filter=BookingOverlapAndAvailabilityTest

# Run one specific use case (e.g., UC-21 duplicate booking prevention)
php artisan test --filter=test_uc21_prevents_exact_duplicate_booking_for_same_vendor

# Verbose / testdox format (best for monitoring & reporting to stakeholders)
php artisan test --testdox \
  tests/Feature/BookingOverlapAndAvailabilityTest.php \
  tests/Feature/BookingLifecycleAndBreaksTest.php \
  tests/Feature/GoogleCalendarSyncAndAvailabilityTest.php \
  tests/Feature/BookingAuthorizationAndTwilightTest.php
```

### How to Monitor Results & Track Fix Progress
- **Baseline Run (Before Fixes)**: Many of these tests are **intentionally written as failing regression tests** exposing verified production bugs. Running them on the unpatched codebase gives you an exact **Red/Green scorecard**.
- **During Bug Fixing**: As each bug is plugged in `bcf-api`, re-run the corresponding `--filter=test_ucXX...` command. A bug is **not** considered fixed until its automated test turns **GREEN** AND the human verification check below passes.
- **Target State (Before Release)**: **34/34 tests PASSING (0 failures, 0 errors)** with `--testdox` output saved as release sign-off evidence.
- **Watching for Flakiness / Environment Issues**:
  - If a test throws `SQLSTATE` migration errors on SQLite (e.g., MySQL-specific `ENUM` or `CHECK` constraints), switch `phpunit.xml` temporarily to a dedicated local MySQL test database (`bcf_test`) and re-run.
  - Always check `storage/logs/laravel.log` after a run for swallowed exceptions (e.g., inside `SyncOrderCalendarEvents` or `OrderController`).

---

## 2. Suite 1: `BookingOverlapAndAvailabilityTest.php` (13 Tests)

**File:** `bcf-api/tests/Feature/BookingOverlapAndAvailabilityTest.php`

### 1. `test_uc03_multi_service_order_with_two_different_vendors_succeeds` (UC-03)
- **What It Tests:** Creating a single order with 2 services assigned to 2 different vendors at the same property and same time (`09:00–10:00`).
- **Expected Automated Result:** HTTP `201`, `OrderSlot::count() === 2`, and `SyncOrderCalendarEvents` job dispatched. *(Expected to PASS on baseline.)*
- **How to Verify as a Human:**
  1. Log in as an Agent and book 2 services (e.g., Photos + Floor Plan) for the same property at `09:00–10:00` using Vendor A and Vendor B.
  2. Check `order_slots` table: 2 rows created for the same `order_id` with different `vendor_id`s.
  3. Confirm both vendors receive booking notifications / calendar sync jobs.
- **What to Monitor:** Ensure neither vendor's slot overwrites the other and both `order_services` rows match their respective `vendor_id`.

### 2. `test_uc09_consecutive_booking_ignores_cancelled_order_slots` (UC-09)
- **What It Tests:** Vendor has `next_booking_slot_only = true` and a **Cancelled** order sitting at `08:00–09:00`. A new booking at `08:00–09:00` (start of work day) must be allowed.
- **Expected Automated Result:** HTTP `201`. *(Currently **FAILS** on baseline because `validateConsecutiveBooking()` does not exclude cancelled orders' slots.)*
- **How to Verify as a Human:**
  1. Enable `next_booking_slot_only` on a vendor whose workday starts at `08:00`.
  2. Book `08:00–09:00`, then cancel that order.
  3. Try booking `08:00–09:00` again as an Agent — it should succeed instead of forcing `09:00`.
- **What to Monitor:** Watch for `422` errors citing "must book consecutively after 09:00" when no active booking exists at 08:00.

### 3. `test_uc12_update_slot_time_is_admin_only` (UC-12)
- **What It Tests:** `POST /api/orders/update-slot-time` rejects Agents (`403`) and allows Admins (`200`) to trim a slot from `14:00–16:00` to `14:00–15:45`.
- **Expected Automated Result:** Agent → `403`; Admin → `200`; DB `order_slots.end_time === '15:45:00'`. *(Expected to PASS on baseline.)*
- **How to Verify as a Human:**
  1. Send `POST /api/orders/update-slot-time` with an Agent Bearer token in Postman → verify `403`.
  2. Repeat with an Admin Bearer token → verify `200` and confirm `end_time` updated in DB.
- **What to Monitor:** Verify the route never accepts Agent, SubAccount, or Vendor tokens.

### 4. `test_uc14_store_persists_custom_duration_and_buffer_minutes` (UC-13, UC-14)
- **What It Tests:** Admin creates an order via `POST /api/orders` with `custom_duration: 45` and `buffer_minutes: 15` on a slot.
- **Expected Automated Result:** HTTP `201`; DB `order_slots.custom_duration === 45` and `buffer_minutes === 15`. *(Currently **FAILS** on baseline because `OrderController::store()` drops both fields.)*
- **How to Verify as a Human:**
  1. Send `POST /api/orders` as Admin with `custom_duration: 45, buffer_minutes: 15`.
  2. Inspect the newly created row in `order_slots` — both columns must retain their values (not `NULL`/`0`).
- **What to Monitor:** Watch for silent data loss where the API returns `201` but `custom_duration` is `NULL` in DB.

### 5. `test_uc17_agent_cannot_bypass_google_calendar_conflict_with_schedule_override` (UC-17)
- **What It Tests:** Vendor has a Google Calendar event (`09:00–10:30`). An Agent attempts to book `09:30–10:30` while spoofing `schedule_override: true` in the payload.
- **Expected Automated Result:** HTTP `422` (rejected). *(Currently **FAILS** on baseline because `store()` trusts `schedule_override` from any role.)*
- **How to Verify as a Human:**
  1. Connect a vendor to Google Calendar and add an event `09:00–10:30`.
  2. Using an Agent token in Postman, `POST /api/orders` for `09:30–10:30` with `"schedule_override": true`.
  3. Confirm the API rejects it with `422` and does NOT create an order.
- **What to Monitor:** Also inspect `order_slots.schedule_override` for normal Agent bookings — it must be `false` (0), never defaulting to `true` (1).

### 6. `test_uc21_prevents_exact_duplicate_booking_for_same_vendor` (UC-21)
- **What It Tests:** Two consecutive `POST /api/orders` requests for the exact same vendor, date, and time (`09:00–10:00`).
- **Expected Automated Result:** First → `201`; Second → `409` or `422`; `OrderSlot::count() === 1`. *(Currently **FAILS** on baseline — zero DB overlap check exists!)*
- **How to Verify as a Human:**
  1. Open two browser tabs (or Postman tabs) for the same vendor & date.
  2. Book `09:00–10:00` in Tab 1 (succeeds).
  3. Submit the exact same `09:00–10:00` in Tab 2 → must fail with a clear conflict message, and DB must only have 1 slot.
- **What to Monitor:** Watch for race conditions when two requests arrive within milliseconds; verify `lockForUpdate()` is used inside a DB transaction.

### 7. `test_uc22_prevents_partial_and_enclosing_slot_overlaps` (UC-22)
- **What It Tests:** Existing booking `10:00–11:00`. Attempts to book `10:30–11:30` (tail), `09:30–10:30` (head), `09:00–12:00` (enclosing), and `10:15–10:45` (contained).
- **Expected Automated Result:** All 4 rejected with `409` or `422`. *(Currently **FAILS** on baseline.)*
- **How to Verify as a Human:**
  1. Book `10:00–11:00` for Vendor A.
  2. Via Postman, try each of the 4 overlapping time ranges on the same date for Vendor A.
  3. All 4 must be rejected without creating rows in `orders` or `order_slots`.
- **What to Monitor:** Ensure partial overlaps are checked using strict `existing.start_time < new.end_time AND existing.end_time > new.start_time`.

### 8. `test_uc23_allows_back_to_back_exact_boundary_slots` (UC-23)
- **What It Tests:** Booking `09:00–10:00` followed immediately by `10:00–11:00` for the same vendor at the same property.
- **Expected Automated Result:** Both return `201`; `OrderSlot::count() === 2`. *(Expected to PASS — guards against overly strict `<=` / `>=` overlap bugs.)*
- **How to Verify as a Human:** Book `09:00–10:00`, then book `10:00–11:00` at the same property for the same vendor; confirm both succeed.
- **What to Monitor:** If this fails after adding overlap checks, the overlap query mistakenly used `<=` / `>=` instead of `<` / `>`.

### 9. `test_uc24_rejects_overlapping_slots_within_same_order_request` (UC-24)
- **What It Tests:** A single `POST /api/orders` containing 2 slots for the **same** vendor that overlap each other (`09:00–10:00` and `09:30–10:30`).
- **Expected Automated Result:** HTTP `409` or `422`. *(Currently **FAILS** on baseline.)*
- **How to Verify as a Human:** Send one `POST /api/orders` payload with two overlapping slots for the same `vendor_id` → must be rejected and rolled back cleanly.
- **What to Monitor:** Confirm no orphan `orders` or `order_services` rows are left behind on rejection.

### 10. `test_uc26_cancelled_order_releases_slots_and_excludes_from_availability_endpoints` (UC-26, UC-27)
- **What It Tests:** Create order → cancel order (`POST /api/orders/{uuid}/cancel`) → verify `order_slots.status === 'cancelled'`, verify `GET /api/order-slots` excludes it, and verify rebooking the same time succeeds (`201`).
- **Expected Automated Result:** Slot status becomes `'cancelled'`, absent from `/api/order-slots`, rebooking returns `201`. *(Currently **FAILS** on baseline — `cancel()` never updates `order_slots.status`!)*
- **How to Verify as a Human:**
  1. Book `09:00–10:00` for Vendor A.
  2. Cancel the order.
  3. Query `SELECT status FROM order_slots WHERE order_id = ?` → must be `'cancelled'`.
  4. Call `GET /api/order-slots` → cancelled slot must NOT appear.
  5. Rebook `09:00–10:00` for Vendor A → must succeed.
- **What to Monitor:** Also check `GET /api/vendors` response (`order_slots` relation) to confirm cancelled slots don't linger there.

### 11. `test_uc28_updating_order_does_not_self_conflict_with_existing_slot` (UC-28)
- **What It Tests:** Updating an existing order (e.g., adding a note) via `PUT /api/orders/{uuid}` while keeping its existing slot time unchanged.
- **Expected Automated Result:** HTTP `200`. *(Guards against overlap checks accidentally colliding with the order's own slot.)*
- **How to Verify as a Human:** Edit an existing order's notes or property info and save without changing its appointment time → must save cleanly with `200`.
- **What to Monitor:** Watch for false `"Slot already booked"` errors during normal order edits.

### 12. `test_uc30_rejects_inverted_and_zero_length_slots` (UC-30)
- **What It Tests:** Submitting `start_time = 11:00:00, end_time = 09:00:00` or `start_time = 10:00:00, end_time = 10:00:00`.
- **Expected Automated Result:** HTTP `422` for both. *(Currently **FAILS** on baseline — no `end_time > start_time` validation.)*
- **How to Verify as a Human:** Send inverted or zero-minute slot times to `POST /api/orders` and `POST /api/orders/update-slot-time` → both must return `422`.
- **What to Monitor:** Also test cross-midnight custom duration (e.g., `23:30` + 60m) to ensure it does not wrap to `00:30` on the same date.

### 13. `test_uc38_store_applies_vendor_travel_rate_fallback` (UC-38)
- **What It Tests:** Vendor has `payment_per_km = 1.50`. Order is created with `distance = '20'` and `km_price` omitted.
- **Expected Automated Result:** HTTP `201`; `order_slots.km_price == 1.50`. *(Currently **FAILS** on baseline — `store()` ignores `payment_per_km` fallback unlike `update()`.)*
- **How to Verify as a Human:** Set `payment_per_km = 1.50` on a vendor, create an order without `km_price` in payload, and verify `order_slots.km_price` in DB equals `1.50`.
- **What to Monitor:** Ensure `store()` and `update()` produce identical travel pricing.

---

## 3. Suite 2: `BookingLifecycleAndBreaksTest.php` (9 Tests)

**File:** `bcf-api/tests/Feature/BookingLifecycleAndBreaksTest.php`

### 14. `test_uc42_vendor_break_blocks_active_slot_overlap_but_allows_cancelled_slot` (UC-42)
- **What It Tests:** Vendor attempts `POST /api/vendor-breaks/add` overlapping an active booking (`10:00–11:00`) → must be rejected (`409`/`422`). Once that slot is cancelled, adding the same break must succeed.
- **Expected Automated Result:** Active overlap → `409`/`422`; Cancelled overlap → `200`/`201`. *(Currently **FAILS** on baseline — `addBreak()` has zero overlap checks.)*
- **How to Verify as a Human:**
  1. Book Vendor A at `10:00–11:00`.
  2. Try adding a Vendor Break `10:30–11:30` → must be rejected.
  3. Cancel the booking, then add the same break → must succeed.
- **What to Monitor:** Check `updateBreak()` as well so editing a break cannot move it onto an active booking.

### 15. `test_uc43_agent_cannot_book_outside_work_hours_or_during_vendor_break` (UC-43)
- **What It Tests:** Vendor works `09:00–17:00` and has a one-time break `14:00–15:00`. Agent tries booking `07:00–08:00` (before work) and `14:15–15:15` (during break).
- **Expected Automated Result:** Both return `422`. *(Currently **FAILS** on baseline — backend never validates slots against `VendorWorkHour` or `VendorBreak`.)*
- **How to Verify as a Human:** Via Postman as an Agent, send `POST /api/orders` outside the vendor's work hours or overlapping a `vendor_breaks` entry → both must fail with `422`.
- **What to Monitor:** Ensure Admins with explicit `schedule_override: true` can still override when intended, while Agents cannot.

### 16. `test_uc44_vendor_work_hours_rejects_end_time_before_start_time` (UC-44)
- **What It Tests:** Updating vendor `work_hours.work_days` with `start_time: '17:00', end_time: '09:00'`.
- **Expected Automated Result:** HTTP `422`. *(Currently **FAILS** on baseline due to broken Laravel `after:start_time` rule on nested array.)*
- **How to Verify as a Human:** Send `PUT /api/vendors/{uuid}` with Monday `start_time: 17:00, end_time: 09:00` → must be rejected with `422`.
- **What to Monitor:** Verify valid hours (`09:00–17:00`) still save normally.

### 17. `test_uc45_vendor_break_and_work_hour_auto_generate_uuid` (UC-45)
- **What It Tests:** Direct `VendorBreak::create(...)` and `VendorWorkHour::create(...)` without passing `uuid`.
- **Expected Automated Result:** Both records created with non-empty UUIDs. *(Currently **CRASHES** on baseline with `Class "App\Models\Str" not found`.)*
- **How to Verify as a Human:** Run in `php artisan tinker`: `VendorBreak::create([...])` without `'uuid'` → should succeed without fatal error.
- **What to Monitor:** Confirm `use Illuminate\Support\Str;` is imported in both models.

### 18. `test_uc46_reassign_slot_checks_conflict_formats_time_and_restricts_to_admin` (UC-46)
- **What It Tests:** `POST /api/orders/slots/{uuid}/reassign`: (1) Agent gets `403`, (2) Admin reassigning onto a busy vendor gets `409`/`422`, (3) Once free, Admin reassigning with `custom_duration: 90` succeeds (`200`), deletes old vendor's Google event, and stores `end_time` in `H:i:s` format.
- **Expected Automated Result:** `403` → `409`/`422` → `200` + `end_time` matches `^\d{2}:\d{2}:\d{2}$`. *(Currently **FAILS** on all 3 checks on baseline!)*
- **How to Verify as a Human:**
  1. Call reassign as Agent → verify `403`.
  2. Call reassign as Admin onto a vendor already booked at that time → verify rejection.
  3. Reassign to a free vendor with `custom_duration: 90` → check `order_slots.end_time` has seconds (`HH:MM:SS`) and old vendor's Google Calendar event is removed.
- **What to Monitor:** Storing `H:i` instead of `H:i:s` breaks downstream Carbon parsing in `SyncOrderCalendarEvents`.

### 19. `test_uc47_swap_slots_checks_conflicts_syncs_order_service_and_blocks_agents` (UC-47)
- **What It Tests:** `POST /api/orders/slots/swap`: (1) Agent gets `403`, (2) Admin swap succeeds (`200`), swaps both `order_slots.vendor_id` AND syncs both `order_services.vendor_id`.
- **Expected Automated Result:** Agent → `403`; Admin → `200` + both `OrderSlot` and `OrderService` updated. *(Currently **FAILS** on baseline — no Agent check and `OrderService.vendor_id` not synced.)*
- **How to Verify as a Human:**
  1. Swap Slot A (Vendor 1) and Slot B (Vendor 2) on Calendar.
  2. Check both `order_slots` AND `order_services` in DB — vendor IDs must match in both tables.
- **What to Monitor:** Also verify swapping fails gracefully (not 500) if either slot has no vendor assigned.

### 20. `test_uc49_update_slot_time_vendor_change_deletes_old_vendor_google_event` (UC-49)
- **What It Tests:** Changing `vendor_uuid` via `POST /api/orders/update-slot-time` deletes Vendor 1's `google_event_id` and sets `slot.google_event_id = null` so the job creates a fresh event on Vendor 2.
- **Expected Automated Result:** Mocked `deleteEvent(vendor1, 'v1-gcal-event-999')` called once; `slot.google_event_id === null`. *(Currently **FAILS** on baseline — old vendor's Google event is orphaned!)*
- **How to Verify as a Human:**
  1. Book Vendor 1 (connected to Google Calendar), confirm event appears on Vendor 1's Google Calendar.
  2. Change vendor to Vendor 2 via Edit Slot Time modal.
  3. Confirm the event disappears from Vendor 1's calendar and appears on Vendor 2's calendar.
- **What to Monitor:** Watch for ghost/duplicate events remaining on the previous vendor's Google Calendar.

### 21. `test_uc50_cancel_service_cancels_all_slots_for_that_service` (UC-50)
- **What It Tests:** An order has 2 slots for the same `service_id` (split booking). Calling `POST /api/orders/{uuid}/cancel-service/{service_uuid}` must cancel **both** slots.
- **Expected Automated Result:** Both `slotA.status` and `slotB.status` become `'cancelled'`. *(Currently **FAILS** on baseline — uses `->first()` so `slotB` stays `'booked'`!)*
- **How to Verify as a Human:** Create an order with 2 slots for the same service, cancel that service, and verify every matching row in `order_slots` is marked `'cancelled'`.
- **What to Monitor:** Check that cancelled split slots no longer block the calendar.

### 22. `test_uc51_cancellation_preview_ignores_cancelled_slots_and_handles_null_work_hours` (UC-51)
- **What It Tests:** Order has an already-cancelled slot 5 hours from now (would be late) and an active slot 48 hours from now (free), on a vendor with **no** `VendorWorkHour` row and `H:i` slot times.
- **Expected Automated Result:** HTTP `200`, `is_free: true`, `cancellation_fee: 0.0`. *(Currently **CRASHES** on baseline due to `->workHours->first()` on null + `H:i:s` format exception + counting cancelled slots!)*
- **How to Verify as a Human:**
  1. Create an order with 2 services (one in 5h, one in 48h). Cancel the 5h service.
  2. Open Cancel Preview for the remaining order → must show **Free cancellation ($0 fee)**, not a 25% late fee or 500 error.
- **What to Monitor:** Watch `laravel.log` for `Call to a member function first() on null` or `InvalidFormatException`.

---

## 4. Suite 3: `GoogleCalendarSyncAndAvailabilityTest.php` (7 Tests)

**File:** `bcf-api/tests/Feature/GoogleCalendarSyncAndAvailabilityTest.php`

### 23. `test_uc53_sync_job_handles_null_work_hours_and_hi_time_format` (UC-53)
- **What It Tests:** Running `SyncOrderCalendarEvents` when the vendor has no `VendorWorkHour` row and slot times are in `H:i` format (`10:00` / `11:30`).
- **Expected Automated Result:** `createEvent` called twice (vendor + agent); both `google_event_id` and `agent_google_event_id` saved. *(Currently **FAILS** silently on baseline!)*
- **How to Verify as a Human:**
  1. Connect a newly created vendor (without work hours saved) to Google Calendar.
  2. Assign them to a slot and run `php artisan queue:work --once`.
  3. Confirm the event appears on Google Calendar and `order_slots.google_event_id` is populated.
- **What to Monitor:** Check `laravel.log` — previously exceptions were caught and logged as warnings while the job reported "succeeded".

### 24. `test_uc54_cancelled_service_deletes_agent_event_and_does_not_recreate_it` (UC-54)
- **What It Tests:** Service is cancelled (`status = 'cancelled'`), vendor is NOT connected to Google Calendar, agent IS connected. Running `SyncOrderCalendarEvents($orderId, [$cancelledSlot->id])` must delete the agent's Google event and **never** call `createEvent`.
- **Expected Automated Result:** `deleteEvent` called once for Agent; `createEvent` called **0 times**; `agent_google_event_id === null`. *(Currently **FAILS** on baseline — skips deletion and immediately re-creates the cancelled event!)*
- **How to Verify as a Human:**
  1. Connect an Agent to Google Calendar (vendor disconnected).
  2. Book an order (event appears on Agent's Google Calendar), then cancel that service.
  3. Verify the event is **deleted** from the Agent's Google Calendar and does NOT reappear.
- **What to Monitor:** Watch for "zombie" cancelled appointments reappearing on Agent Google Calendars.

### 25. `test_uc55_order_destroy_cleans_up_google_calendar_events` (UC-55)
- **What It Tests:** Hard-deleting an order (`DELETE /api/orders/{uuid}`) deletes both Vendor and Agent Google Calendar events.
- **Expected Automated Result:** HTTP `200`; `deleteEvent` called once for Vendor and once for Agent. *(Currently **FAILS** on baseline — `destroy()` has zero Google cleanup.)*
- **How to Verify as a Human:** Delete a synced order as Admin → confirm its events are removed from both Vendor's and Agent's Google Calendars.
- **What to Monitor:** No orphaned events left on Google Calendar after order deletion.

### 26. `test_uc56_all_day_google_event_does_not_block_next_day` (UC-56)
- **What It Tests:** A 3-day all-day Google Calendar event (`Jun 10–12`, Google exclusive `end.date = Jun 13`). `getUnavailableDates()` must return `['2026-06-10', '2026-06-11', '2026-06-12']` as a sequential JSON array — NOT `2026-06-13`.
- **Expected Automated Result:** Sequential list (`array_is_list === true`), contains Jun 10, 11, 12, does NOT contain Jun 13. *(Currently **FAILS** on baseline — only returns day 1, blocks extra day, non-sequential keys.)*
- **How to Verify as a Human:**
  1. Add a 1-day All-Day event on `Jun 10` in the vendor's Google Calendar.
  2. Check vendor availability on `Jun 11` → `Jun 11` MUST be fully bookable!
  3. Add a 3-day All-Day event `Jun 10–12` → `Jun 10, 11, 12` blocked, `Jun 13` bookable.
- **What to Monitor:** Verify JSON response from `/unavailable-dates` is an array `["2026-06-10", ...]`, not an object `{"0": "...", "2": "..."}`.

### 27. `test_uc58_bcf_vendor_event_with_service_title_is_recognized_as_internal` (UC-58)
- **What It Tests:** A BCF vendor Google event titled `"2D Floor Plan"` (not `"Order #123"`) matching an existing `order_slots.google_event_id` must be recognized as an internal BCF event and not cause false self-conflicts in `store()`.
- **Expected Automated Result:** HTTP `201`. *(Currently **FAILS** on baseline because `OrderController::store()` only excludes `/^Order #\d+/i`.)*
- **How to Verify as a Human:** Cancel a synced order and immediately rebook the same slot within 60 seconds (while Google events cache is still warm) → must succeed without false Google Calendar conflict.
- **What to Monitor:** Check that external personal events (whose ID is NOT in `order_slots.google_event_id`) still properly block booking.

### 28. `test_uc59_timezone_resolution_and_disconnect_with_expired_token` (UC-59)
- **What It Tests:** (a) `resolveTimezoneFromProvince('America/Vancouver')` preserves casing (not `'AMERICA/VANCOUVER'`) and maps `'NL'` to `'America/St_Johns'`. (b) `disconnect()` succeeds and clears DB columns even when the token is expired with no refresh token.
- **Expected Automated Result:** Exact timezone strings match; `disconnect()` returns `true` and nulls `google_access_token` / `google_calendar_id`. *(Currently **FAILS** on baseline.)*
- **How to Verify as a Human:**
  1. Revoke app access from a test Google account's security settings, then click **Disconnect Google Calendar** in BCF.
  2. Confirm BCF disconnects cleanly (`200`) instead of throwing a `500` error.
- **What to Monitor:** Watch for stuck "Connected" state when users revoke tokens from Google's side.

### 29. `test_uc60_sync_status_excludes_cancelled_slots_and_sync_manual_enforces_ownership` (UC-60)
- **What It Tests:** (a) `GET /api/calendar/sync-status` reports `unsynced_count === 0` when the only unsynced slot is `cancelled`. (b) Agent from Org 2 calling `POST /api/calendar/sync-manual` with Org 1's `order_id` is rejected (`403`/`404`/`422`).
- **Expected Automated Result:** `unsynced_count === 0`; cross-org `sync-manual` rejected. *(Currently **FAILS** on baseline.)*
- **How to Verify as a Human:**
  1. Cancel a service on an order, then check the Calendar Sync Status modal → cancelled slots must not inflate the "unsynced appointments" warning.
  2. Send another org's `order_id` to `POST /api/calendar/sync-manual` → must be rejected.
- **What to Monitor:** Ensure `syncManual` only syncs orders owned by/assigned to the authenticated user.

---

## 5. Suite 4: `BookingAuthorizationAndTwilightTest.php` (5 Tests)

**File:** `bcf-api/tests/Feature/BookingAuthorizationAndTwilightTest.php`

### 30. `test_uc61_twilight_vendor_availability_checks_correct_day_of_week` (UC-61)
- **What It Tests:** `TwilightCalculatorService::isVendorAvailableForTwilight()` returns `true` on Wednesday (`wed: is_twilight = true`) and `false` on Thursday (`thu: is_twilight = false`).
- **Expected Automated Result:** `true` for Wed, `false` for Thu. *(Currently **FAILS** on baseline — `format('ddd')` outputs `'171717'`, breaking 100% of twilight bookings!)*
- **How to Verify as a Human:**
  1. Enable Twilight on Wednesday for Vendor A.
  2. Book a Twilight service for Vendor A on a Wednesday within the sunset window → must succeed (`201`) instead of rejecting with `422`.
- **What to Monitor:** Also test booking a Twilight service on a day where `is_twilight = false` → must still be rejected (`422`).

### 31. `test_uc62_twilight_sunset_accurate_for_toronto_and_dst_transition` (UC-62)
- **What It Tests:** (a) `GET /api/twilight-window?date=2026-06-21&province=ON` returns sunset around `20:xx–21:xx` (not `00:20` from Vancouver coords). (b) Vancouver DST spring-forward Sunday (`2026-03-08`) sunset is `19:xx PDT` (not `18:xx PST`).
- **Expected Automated Result:** Toronto June sunset hour ∈ `{20, 21}`; Vancouver DST Sunday sunset hour === `19`. *(Currently **FAILS** on baseline.)*
- **How to Verify as a Human:** Call `GET /api/twilight-window?date=2026-06-21&province=ON` in browser/Postman and verify sunset is ~`21:03` (9:03 PM), matching real Toronto sunset.
- **What to Monitor:** Check both spring-forward (March) and fall-back (November) DST Sundays.

### 32. `test_uc64_order_slots_org_isolation_and_oauth_tokens_hidden` (UC-64)
- **What It Tests:** (a) `GET /api/order-slots` as Org 1 Admin must NOT return Org 2's slots. (b) `Vendor::toArray()` and `Agent::toArray()` must NOT expose `google_access_token` or `google_refresh_token`.
- **Expected Automated Result:** Org 2 slot absent from response; OAuth token keys absent from serialized models. *(Currently **FAILS** on baseline — major security & tenant leak!)*
- **How to Verify as a Human:**
  1. Log in as Org 1 Admin and inspect `GET /api/order-slots` network response → confirm zero slots from other organizations.
  2. Inspect `GET /api/vendors` and `GET /api/agents` network responses → confirm `google_access_token` and `google_refresh_token` are NOT present in JSON.
- **What to Monitor:** Verify UI still knows connection status via boolean `sync_google_calendar` / `is_google_connected` without ever receiving raw tokens.

### 33. `test_uc10_subaccount_and_other_agent_cannot_cancel_or_modify_unowned_order` (UC-07, UC-10)
- **What It Tests:** SubAccount of Agent 1 attempts to cancel Agent 2's order (`403`), and Agent 1 attempts to `PUT /api/orders/{uuid}` with `payment_status: 'PAID'` on Agent 2's order (`403`).
- **Expected Automated Result:** Both return `403`. *(Currently **FAILS** on baseline!)*
- **How to Verify as a Human:** Using Agent 1's token, try cancelling or updating Agent 2's order UUID in Postman → must return `403 Forbidden`.
- **What to Monitor:** Confirm Agent 1 CAN still view/cancel their own orders and co-agented orders.

### 34. `test_uc65_role_authorization_matrix_and_agent_availability_access` (UC-65)
- **What It Tests:** (1) Agent blocked (`403`) from `PATCH /api/orders/media-access/update`, (2) Agent blocked (`403`) from `DELETE /api/orders/{uuid}`, (3) Vendor 1 blocked (`403`) from adding a break to Vendor 2, (4) Agent **allowed** (`200`) to call `GET /api/vendors/{uuid}/busy-slots` using vendor UUID.
- **Expected Automated Result:** `403`, `403`, `403`, `200`. *(Currently **FAILS** on all 4 checks on baseline!)*
- **How to Verify as a Human:**
  1. Test each of the 3 restricted endpoints with an Agent/Vendor token in Postman → verify `403`.
  2. Test `GET /api/vendors/{vendor_uuid}/busy-slots?start_date=...&end_date=...` with an Agent token → verify `200`.
- **What to Monitor:** Confirm Admin (`api` guard) retains full access to all 4 endpoints.

---

## 6. Sign-Off Checklist for QA / Developer Handoff

- [ ] Run full 4-file PHPUnit command and record baseline pass/fail count.
- [ ] Verify every **Phase 1 (P0)** fix turns its corresponding `test_uc*` green.
- [ ] Verify every **Phase 2 (P1)** fix turns its corresponding `test_uc*` green.
- [ ] Verify every **Phase 3 (Security/RBAC)** fix turns its corresponding `test_uc*` green.
- [ ] Confirm **34 / 34 tests PASS** with `0 errors, 0 failures`.
- [ ] Pair with the separate UI/Puppeteer suite (`ui_puppeteer_automation_suite.md`) for full 65/65 end-to-end coverage.
