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
