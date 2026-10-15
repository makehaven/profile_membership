# Profile Membership

This lightweight helper module lets MakeHaven offer two simultaneous onboarding experiences:

- **Public / non-member accounts** continue to use the regular Drupal registration form with no extra requirements.
- **Members coming from Chargebee** are selectively funneled through a guided flow that ensures they complete the full member profile before accessing member-only systems.

## How it works

1. Chargebee returns buyers to `/membership-initiate` with the payment metadata (email, subscription id, etc.) as query parameters.
2. The controller looks up the user by email.  
   - If the account does not exist yet, the visitor is redirected to `user/register` with the original query string so Drupal creates the user record first.  
   - If the account exists, the module stores the expected user ID and the Chargebee parameters in the session.
3. Authenticated visitors who already match the expected account jump straight to `/membership-finalize`. Otherwise they are prompted to log in; once authenticated, the destination is `/membership-finalize`.
4. During `finalize` the module verifies that the logged-in user matches the Chargebee UID, adds the `member_pending_approval` role if it is missing, clears the temporary session data, and finally redirects the member to `/user/<uid>/main` (the “member profile” form) with the original Chargebee query parameters intact.

Because the flow only triggers when `/membership-initiate` is called, non-member registrations remain untouched. Members, however, are guaranteed to land in the correct profile-edit screen with their payment context available to downstream hooks and automation.

## Contact fields: required at join, prompted afterwards

`field_emergency_contact_name`, `field_emergency_contact_phone` and
`field_preferred_phone` used to be `required: TRUE` in field config. That made
them a **lock rather than a question**: 115 of 858 active members (13.4%,
measured on live 2026-08-17) were missing at least one, and therefore could not
save *any* edit to their profile — a bio, a headshot, an interest — until they
supplied an emergency contact nobody had ever asked them for. It is what turned
every "you can add this later" path in onboarding into a dead end, and it was
routinely misreported as "the bio field is required" (bio is not required).

The field config is now optional. The requirement is asserted in
`profile_membership_form_alter()` instead, on the **join path only** — the same
"member has no active door badge" condition the field deferral uses. So:

| Who | Behaviour |
|---|---|
| Joining member (no door badge) | All three fields `#required`. Nothing changes for them. |
| Established member, fields filled | Nothing changes. |
| Established member, any field empty | Warning at the top of the profile form linking to the capture form. The save is **not** blocked. |

`/membership/emergency-contact` (`EmergencyContactForm`) is the matching active
capture moment — three questions, saved on their own, with no other profile
validation in the way. It mirrors `UpdateAddressForm`, and is surfaced as an
"Emergency Contact" member quick link via `hook_makerspace_user_links_links()`.

Keep the field list in `_profile_membership_contact_fields()`; the form alter,
the capture form and `_profile_membership_missing_contact_fields()` all read it.

> A deferred field needs its own active capture moment or it is not collected.
> Re-adding these to the join form is not the fix — that is what was already
> being done, and it is what locked people out.

## Mailing-address confirmation (year-end appeal)

Before a postal appeal, members and donors are asked whether the address in
CiviCRM is still right. Settings: `profile_membership.settings:address_campaign`
(`enabled`, `start` as Y-m-d, `snooze_days`, `checkin_webform`). An answer
dated before `start` counts as stale, so next year's campaign only needs a new
start date and check-in webform.

- **Members** see "Is your mailing address still right?" in the member-attention
  strip on `/member/resources` (`hook_makerspace_member_attention()`), linking to
  `/membership/address/confirm`. The item clears on any answer. "Not now" hides
  it for `snooze_days`. Members who are already `do_not_mail` are not asked.
- **Donors who are not members** get a CiviMail with a checksum link to
  `/address/confirm?cid=…&cs=…` (no login). Build the recipient groups with
  `drush pm-address-groups` (dry run; `--apply` writes; `--since`,
  `--personal-min`). Re-running before a reminder leaves only non-responders.
  Donors at or above `--personal-min` go to a staff "personal mail" group and
  are kept out of the email.
- **The form** has "Yes, this is correct", an update panel, and a small "Don't
  send me mail" button. That button opens a confirm step, because it sets CiviCRM
  `do_not_mail`, which stops **all** postal mail.
- **Recorded on the contact:** custom group `Address_Confirmation` (`Confirmed_on`,
  `Response`: confirmed / updated / no_mail), created by update 9002. Logged-in
  answers are mirrored to user.data (`address_confirmed`) so the strip can skip
  CiviCRM.
- **Step 2 for members** is `/membership/check-in`. It is the successor to the 2025
  survey's profile-update blocks. It asks only what is still blank:
  - volunteer ways and availability (`makehaven_tasks` "How I like to help")
  - employer (`field_member_company`)
  - emergency contact
  - "This year MakeHaven helped me to…" plus a story with share permission, saved
    to the `checkin_webform` webform

  Nothing is overwritten, and when nothing is left to ask the page goes straight
  back to resources.
