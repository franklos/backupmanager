# Provider administration security

Normal administration runs in Nextcloud Backupbeheer. Only Nextcloud administrators
can call its management controller; Nextcloud middleware and explicit group/CSRF
checks protect both inventory and action routes. Approval/rejection are explicit
POST actions. Management credentials reside only server-side at
`/etc/backupmanager/management-token`, root:www-data 0640, inside the protected
configuration directory. Ordinary managed client installations must not receive
this provider-wide credential. The browser sees only configured/not-configured
and allowlisted request metadata/public fingerprints.

The provider validates the existing bearer management credential before listing
or mutating requests. HTTPS is mandatory; redirects are not followed by the
Nextcloud client. Non-JSON media types, malformed envelopes and mismatched action
responses are rejected. Diagnostic logs contain stable error codes and response
metadata rather than bodies, tokens, private keys or database credentials.
Approval/rejection audit events include the authenticated Nextcloud actor ID.

Standalone `/admin/` is an emergency interface using the provider's own password
session. Keep HTTPS, secure/HTTP-only/SameSite cookies, CSRF, session rotation,
expiry, login throttling and password-rotation invalidation. Remove the redundant
Apache Basic Auth block rather than weakening application authentication. Apache
must forward Authorization to FPM and fail closed when proxy_fcgi is absent.
The existing dedicated bmprovider pool and restricted backupstore SSH account
remain separate from Nextcloud's web account.

All provider code reads `/etc/backupmanager-provider/config.php`, root:bmprovider
0640 in a 0750 directory. Secret configuration is outside public/. Password hashes
and management tokens are private files, not browser configuration fields.
The old /opt configuration argument is no longer a deployment fallback.

Approval locks and checks the request and existing client/source binding. Expired
requests have no actions. Recovery cannot allocate another client or recreate a
missing storage directory. Two validated keys receive separate rrsync -wo/-ro
restrictions on the same existing allocation. Credential transactions preserve
old authorization lines for rollback; unfinished journals require reconciliation
before another replacement. Rejection never provisions or removes credentials.

Fixtures exercise these boundaries with synthetic credentials, private databases,
loopback-only servers and temporary storage. Deployment/approval of a live request
is always a separate operator action; no test grants access to live storage.
