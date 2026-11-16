# HTTPS and proxies

Yadeit must be served over HTTPS in production: session cookies are
`Secure`, and OAuth clients refuse plain HTTP. Choose one of two setups,
controlled by `SERVER_NAME`.

## Behind your own proxy (the default)

Keep `SERVER_NAME=:8080`. The container speaks plain HTTP on the host port
`HTTP_PORT` (default 8080). Your proxy or load balancer terminates HTTPS and
forwards everything to it.

```bash
SERVER_NAME=:8080
HTTP_PORT=8080
TRUSTED_PROXIES=10.0.0.5   # the proxy's address, or * if the container is reachable only through it
```

The proxy must:

- forward **every path** to the container, including `/app/*` WebSocket upgrades when realtime is on;
- pass `X-Forwarded-For`, `X-Forwarded-Proto` and `X-Forwarded-Host`;
- allow request bodies of at least 30 MB (uploads are up to 20 MB);
- not buffer or time out WebSocket connections under a minute (Reverb pings every 60 s).

Example for nginx:

```nginx
server {
    listen 443 ssl;
    server_name yadeit.example.com;
    client_max_body_size 30m;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Host $host;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_read_timeout 120s;
    }
}
```

> **Warning.** Without `TRUSTED_PROXIES` every request appears to come from the
> proxy. Rate limits are then shared by all callers, and the audit log records
> the proxy's address for every sign-in.

## Let the container do it

Set `SERVER_NAME` to the domain. Caddy inside the container obtains and renews
a Let's Encrypt certificate by itself. In `compose.prod.yaml`, replace the
`HTTP_PORT` mapping of the `app` service with the commented `80:80` and
`443:443` lines, and leave `TRUSTED_PROXIES` empty.

```bash
SERVER_NAME=yadeit.example.com
TRUSTED_PROXIES=
```

Ports 80 and 443 must be reachable from the internet for the certificate
challenge. Certificates are kept in the `caddy` volume.

## Checking it

```bash
curl -sI https://yadeit.example.com/up          # HTTP/2 200
curl -s https://yadeit.example.com/api/v1/me -o /dev/null -w '%{http_code}\n'   # 401 when signed out
```

Then sign in from a browser and reload: if you stay signed in, the cookie
settings are right.
