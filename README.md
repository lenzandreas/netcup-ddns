# netcup DDNS

A small PHP script that acts as a Dynamic DNS (DynDNS) endpoint for domains managed with **netcup CloudDNS**. A router (e.g. a Fritz!Box) or any other client calls the script with its current IP address, and the script updates the matching A and/or AAAA records through the netcup DynDNS API.

## Requirements

- A web server with PHP 7+ and the `curl` extension, reachable over HTTPS
- A domain managed through **CloudDNS** (it shows a "CloudDNS" tab in the netcup CCP, not a "DNS" tab)
- A netcup API key from the CCP under *Master data > API > API-Keys* (a regular key, **not** a "Legacy API key")
- The A/AAAA records you want to update must already exist

## Setup

1. Copy `netcup-ddns.php` to your web server.
2. Edit the constants at the top of the file:

   | Variable          | Meaning                                                                  |
   |-------------------|--------------------------------------------------------------------------|
   | `$updateUser`     | Username callers must supply                                             |
   | `$updatePass`     | Password callers must supply. Choose a long, random value                |
   | `$apiKey`         | Your netcup API key                                                      |
   | `$allowedDomains` | Zones that may be updated. Subdomains of a listed zone are allowed too   |
   | `$maxNames`       | Maximum number of names per call (default 10)                            |

3. Serve the script over HTTPS only, since credentials are sent with each request.

## Usage

Call the script with these parameters:

| Parameter  | Description                                                                  |
|------------|------------------------------------------------------------------------------|
| `user`     | Username (or use HTTP Basic Auth instead)                                   |
| `password` | Password (or use HTTP Basic Auth instead)                                    |
| `domain`   | Fully qualified name to update. Separate several names with commas           |
| `ipv4`     | New IPv4 address (optional if `ipv6` is given)                               |
| `ipv6`     | New IPv6 address (optional if `ipv4` is given). Empty values are ignored     |

Parameters can be passed in the URL (GET) or as a form-encoded POST body.

### Example

```bash
curl "https://your.host/netcup-ddns.php?user=your-username&password=your-password&domain=ddns.example.com&ipv4=203.0.113.10"
```

With HTTP Basic Auth and several names:

```bash
curl -u your-username:your-password \
  --data "domain=ddns.example.com,www.example.com" \
  --data "ipv4=203.0.113.10" \
  --data "ipv6=2001:db8::1" \
  https://your.host/netcup-ddns.php
```

### Fritz!Box

Under *Internet > Shares > DynDNS*, choose a custom provider and set:

- **Update URL:**
  `https://your.host/netcup-ddns.php?user=<username>&password=<pass>&domain=<domain>&ipv4=<ipaddr>&ipv6=<ip6addr>`
- **Domain name:** your DDNS name, e.g. `ddns.example.com`
- **Username / Password:** the values of `$updateUser` and `$updatePass`

The Fritz!Box replaces `<username>`, `<pass>`, `<domain>`, `<ipaddr>` and `<ip6addr>` itself.

## Responses

The script answers in plain text, one line per domain:

| HTTP status | Meaning                                                            |
|-------------|--------------------------------------------------------------------|
| 200         | All records updated                                                |
| 400         | Missing or invalid parameter, or domain not in `$allowedDomains`   |
| 401         | Wrong username or password                                         |
| 500         | At least one update failed (the message says which and why)        |

## Security notes

- The script only updates names inside `$allowedDomains`, so callers cannot touch other domains in your netcup account.
- Credentials are compared in constant time.
- The API key is sent to netcup in a POST body, so it never appears in a URL or log.
- Don't commit your real credentials or API key to a public repository.
