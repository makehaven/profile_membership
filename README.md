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
