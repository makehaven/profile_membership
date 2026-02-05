# Profile Membership Module

This is a custom Drupal module for the MakeHaven website that manages the selective onboarding flow for members arriving from Chargebee after a payment.

## Project Overview

The module provides a bridge between external Chargebee payments and the Drupal user system. It ensures that members are correctly identified, authenticated, and assigned the `member_pending_approval` role before being sent to complete their profile.

- **Main Technology:** PHP (Drupal 10+ API)
- **Dependencies:** `drupal:profile`
- **Namespace:** `Drupal\profile_membership`

## Architecture & Logic

The module operates through a two-step session-backed controller:

1.  **Initiation (`/membership-initiate`):**
    - Triggered by Chargebee redirects with an `email` parameter.
    - Checks if a user with that email exists.
    - If not, redirects to `user/register`.
    - If yes, stores the `expected_uid` and Chargebee query parameters in the session.
    - Redirects to `/membership-finalize` (forcing login if necessary).

2.  **Finalization (`/membership-finalize`):**
    - Validates that the logged-in user matches the `expected_uid` in the session.
    - Grants the `member_pending_approval` role to the user.
    - Clears the session data.
    - Redirects the user to their main profile edit page (`/user/{uid}/main`) while preserving original Chargebee parameters for downstream processing.

## Key Files

- `profile_membership.info.yml`: Module definition and dependencies.
- `profile_membership.routing.yml`: Route definitions for the initiation and finalization endpoints.
- `src/Controller/MembershipController.php`: Core logic for handling redirects, session management, and role assignment.

## Development & Maintenance

### Building and Running

This is a Drupal module and does not require a separate build step. However, it should be managed within the Lando environment.

- **Clear Cache:** `lando drush cr`
- **Enable Module:** `lando drush en profile_membership`
- **Export Config:** `lando drush cex`

### Testing

While there are no automated tests in this module directory, testing the flow involves:
1. Navigating to `/membership-initiate?email=test@example.com&sub_id=123`.
2. Verifying the redirect to login or registration.
3. Verifying the final landing page and role assignment after login.

### Conventions

- **Session Handling:** The module uses the standard Drupal session service via the Request object.
- **Roles:** It explicitly depends on the existence of a `member_pending_approval` role in the Drupal site.
- **Routing:** Uses `_user_is_logged_in: 'TRUE'` for security on the finalize route.
