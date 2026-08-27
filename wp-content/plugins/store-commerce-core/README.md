# Commerce Core production configuration

Commerce Core owns shared environment policy, redacted operational logging, schema coordination, cache safety, provider contracts, and health checks. Presentation plugins remain separate. Mock payment and fulfillment plugins are isolated and return before registering gateways, callbacks, or jobs unless mocks are explicitly allowed.

## Environments and secrets

Set `WP_ENVIRONMENT_TYPE` to `local`, `development`, `staging`, or `production` at the server level. Mocks are enabled by default only for local/development. Staging must explicitly set `STORE_ALLOW_MOCK_PROVIDERS=true`; production forces it off.

Inject these as server environment variables or constants outside the deployed repository:

- `STORE_PAYMENT_WEBHOOK_SECRET`
- `STORE_FULFILLMENT_CALLBACK_SECRET`
- future `STORE_PAYMENT_PROVIDER_*`
- future `STORE_SUPPLIER_PROVIDER_*`
- `STORE_EMAIL_FROM_NAME`
- `STORE_EMAIL_FROM_ADDRESS`

Local generated secrets may remain in WordPress options. Production callbacks fail closed when an injected secret is absent. Never put credentials in themes, JavaScript, deployment manifests committed to source control, or WooCommerce logs. WordPress database credentials and authentication salts should likewise be injected by the production host or an untracked host-level config.

## Cache exclusions

Never cache `/cart/`, `/checkout/`, `/my-account/`, WooCommerce `wc-ajax` requests, WordPress REST routes under `/store-payment-test/` or `/store-fulfillment/`, payment-provider webhook routes, supplier callback routes, or any response carrying WooCommerce session cookies. Commerce Core emits `no-store` headers for Cart, Checkout, and My Account. Mirror these exclusions at the reverse proxy/CDN.

## WordPress baseline

Production disables the built-in theme/plugin file editor and mock providers. Keep debug display off; send diagnostics to protected server logs. XML-RPC should be disabled at the edge if no integration uses it, otherwise rate-limit it. Do not globally disable REST because WooCommerce depends on it. Enforce unique administrator accounts, strong passwords/MFA at the identity layer, least privilege, HTTPS, and host-managed filesystem ownership (web server writes only to uploads/cache/update locations). Take and verify database plus uploads backups before deployments, schema changes, core/plugin/theme updates, and provider cutovers. Use staged automatic updates with backups and rollback, rather than unattended production changes without recovery testing.

## Provider boundary

`Store_Payment_Provider_Interface`, `Store_Supplier_Provider_Interface`, and `Store_Email_Transport_Interface` define the future adapters. Provider implementations translate external responses into existing normalized states; checkout, orders, fulfillment, returns, and customer notifications must not depend on a provider SDK directly.
