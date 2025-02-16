# PD Subscription Manager

PD Subscription Manager is a plugin for automatically managing user subscriptions with WooCommerce integration.

![PD Subscription Manager is a plugin for automatically managing user subscriptions with WooCommerce integration.](https://github.com/PhantomDraft/subscription-manager-wp/blob/main/cover.png)

It performs the following functions:

- **Subscription Role Assignment:**  
  When a subscription product is purchased, the user is assigned a specified role for a set period calculated as the product of the number of copies purchased and the number of days (1 copy = 1 month). If the rule does not specify a number of days, a global value is used (default is 30 days, configurable via the admin panel).

- **Automatic Subscription Extension:**  
  The plugin automatically extends the subscription period if the user makes additional purchases. Once the subscription expires (checked daily via WP‑Cron or via the "Refresh Subscriptions" button in settings), the user's role is reverted to the default if specified.

- **Admin Panel Pages:**  
  The admin panel provides two pages:
  - **PD Subscription Manager** (Subscription Settings)  
  - **Subscribers** (a list of users with active subscriptions, with the ability to manually edit the remaining days)

- **Admin Notifications:**  
  The plugin displays notifications in the admin area about new subscriptions and warns for subscriptions that will expire within the next 5 days.

## Compatibility

This plugin works excellently in conjunction with **PD Hide Site**—a plugin that manages content access based on user groups, allowing dynamically assigned roles to be used for restricting access.
