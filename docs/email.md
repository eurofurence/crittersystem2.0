# Outbound email

Critter sends email through Symfony Mailer. Configure the transport with `MAILER_DSN` and the visible sender address with `MAILER_FROM`.

```env
MAILER_DSN=smtp://user:password@smtp.example.com:587
MAILER_FROM=noreply@example.com
```

`MAILER_FROM` defaults to `noreply@critter.example` for backwards compatibility when the environment variable is not set.

The sender is configured as Symfony Mailer's global `From` header. Application mail paths therefore do not need to repeat a sender address. A message may still explicitly set its own `From` header in the future when a specialized sender is required; Symfony keeps an explicitly set header instead of replacing it with the global default.

Email delivery is asynchronous. `SendEmailMessage` is routed to the `async` Messenger transport, so a running Messenger worker is required for outbound mail. The default `MAILER_DSN=null://null` discards messages; production deployments must provide a real transport such as SMTP.

If the SMTP username, password, or host contains URI-reserved characters, URL-encode those values before putting them in `MAILER_DSN`.
