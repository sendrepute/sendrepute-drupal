#!/usr/bin/env python3
"""Exercise the real admin login, Form API CSRF token, and config save."""

from html.parser import HTMLParser
from http.cookiejar import CookieJar
from urllib.parse import urlencode
from urllib.request import build_opener, HTTPCookieProcessor, Request
from urllib.error import HTTPError
import sys


class Form(HTMLParser):
    def __init__(self, wanted):
        super().__init__()
        self.wanted, self.active, self.action, self.values = wanted, False, "", {}

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == "form":
            self.active = attrs.get("id") == self.wanted
            if self.active:
                self.action = attrs.get("action", "")
        elif self.active and tag in ("input", "textarea"):
            name = attrs.get("name")
            if name:
                self.values[name] = attrs.get("value", "")

    def handle_endtag(self, tag):
        if tag == "form" and self.active:
            self.active = False


def fetch(opener, base, path, values=None):
    data = None if values is None else urlencode(values).encode()
    response = opener.open(Request(base + path, data=data), timeout=30)
    return response.geturl(), response.read().decode("utf-8")


def form(html, form_id):
    parser = Form(form_id)
    parser.feed(html)
    if not parser.action:
        raise RuntimeError(f"Missing real Drupal form {form_id}")
    return parser


def denied(opener, base, path, values=None):
    try:
        fetch(opener, base, path, values)
    except HTTPError as error:
        if error.code == 403:
            return
        raise RuntimeError(f"Expected permission denial, got HTTP {error.code}") from error
    raise RuntimeError("Restricted user reached the protected Drupal configuration page")


base = sys.argv[1].rstrip("/")
opener = build_opener(HTTPCookieProcessor(CookieJar()))
anonymous = build_opener(HTTPCookieProcessor(CookieJar()))
denied(anonymous, base, "/admin/config/system/sendrepute")
restricted = build_opener(HTTPCookieProcessor(CookieJar()))
_, html = fetch(restricted, base, "/user/login")
restricted_login = form(html, "user-login-form")
restricted_login.values.update({"name": "restricted", "pass": "harness-restricted-pass", "op": "Log in"})
url, _ = fetch(restricted, base, restricted_login.action, restricted_login.values)
if "/user/login" in url:
    raise RuntimeError("Real restricted Drupal account could not authenticate")
denied(restricted, base, "/admin/config/system/sendrepute")
_, html = fetch(opener, base, "/user/login")
login = form(html, "user-login-form")
login.values.update({"name": "admin", "pass": "harness-admin-pass", "op": "Log in"})
url, _ = fetch(opener, base, login.action, login.values)
if "/user/login" in url:
    raise RuntimeError("Real Drupal administrator login failed")

_, html = fetch(opener, base, "/admin/config/system/sendrepute")
settings = form(html, "sendrepute-settings")
token = settings.values.get("form_token")
if not token:
    raise RuntimeError("Drupal did not issue a Form API CSRF token")
denied(restricted, base, settings.action, {
    **settings.values, "paid_consent": "1", "sender_display_name": "stolen admin token",
})

# A tokenless submission must not modify config.
invalid = dict(settings.values)
invalid.pop("form_token", None)
invalid.update({"paid_consent": "1", "sender_display_name": "CSRF must fail", "routes": ""})
fetch(opener, base, settings.action, invalid)

_, html = fetch(opener, base, "/admin/config/system/sendrepute")
settings = form(html, "sendrepute-settings")
if settings.values.get("sender_display_name") == "CSRF must fail":
    raise RuntimeError("Drupal accepted a tokenless admin config submission")
malicious = dict(settings.values)
malicious.update({"paid_consent": "1", "sender_display_name": '"><script>alert(600)</script>', "routes": ""})
fetch(opener, base, settings.action, malicious)
_, html = fetch(opener, base, "/admin/config/system/sendrepute")
if "<script>alert(600)</script>" in html:
    raise RuntimeError("Drupal rendered malicious admin input as executable HTML")
settings = form(html, "sendrepute-settings")
if settings.values.get("sender_display_name") == malicious["sender_display_name"]:
    raise RuntimeError("Drupal saved an invalid malicious sender display name")
settings.values.update({
    "paid_consent": "1",
    "sender_display_name": "Harness Store",
    "routes": "sendrepute_harness|selected|php_mail|advisory|preserve|0.90|no",
    "op": "Save configuration",
})
fetch(opener, base, settings.action, settings.values)
print("Real restricted account GET/POST denial, anonymous denial, admin CSRF rejection, malicious input escaping, and authenticated config save passed")