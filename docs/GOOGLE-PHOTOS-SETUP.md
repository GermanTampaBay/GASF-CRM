# Importing from Google Photos — one-time setup

The code is deployed, but it cannot work until the club's Google Cloud project
is told to allow it. Three things, all in the SAME project that already holds
the Google sign-in client — this reuses that client rather than adding a second
one, so volunteers see one app on their Google account, not two.

## 1. Turn the Picker API on

Google Cloud console → **APIs & Services → Library** → search **Photos Picker
API** → **Enable**.

This is not the old Photos Library API. Google removed general library access
for third-party apps in March 2025; the Picker API is what replaced it, and it
is deliberately narrower — the club can never browse or search a library, only
receive what somebody hands over.

## 2. Add the scope to the consent screen

**APIs & Services → OAuth consent screen → Data access → Add or remove scopes**,
then add exactly:

```
https://www.googleapis.com/auth/photospicker.mediaitems.readonly
```

Nothing else. The sign-in scopes (`openid`, `email`, `profile`) stay as they
are: this asks separately, only when somebody presses the button.

## 3. Add the redirect URI

**APIs & Services → Credentials →** the existing OAuth 2.0 Client ID → under
**Authorised redirect URIs**, add:

```
https://germantampabay.com/wp-json/gasf/v1/crm/photos/google/callback
```

It must match exactly, including https and no trailing slash.

## Verification: stay in Testing

The picker scope is *sensitive*, so an app in **Production** used by people
outside the organisation needs Google review. Do not do that. Instead:

**OAuth consent screen → Audience →** leave the app in **Testing**, and add each
photo volunteer under **Test users** (up to 100).

Testing mode is the right answer here and not a workaround: this is a club tool
for a known handful of people, the grant is deliberately short-lived, and no
refresh token is stored — so there is nothing for a review to protect against
that the design does not already prevent. Consent is re-asked periodically,
which is unnoticeable in a flow where the volunteer is present and pressing a
button anyway.

If a volunteer is not on the Test users list, Google refuses at the consent
screen with "access blocked". That is the symptom to look for.

## What the club can and cannot see

- **Cannot** browse, search, or list anybody's Google Photos.
- **Can** receive the specific items a volunteer picked, for 60 minutes.
- Stores no refresh token: the grant dies with the import. Pressing the button
  tomorrow asks again.
- Google strips **location** from the downloaded copies, so no home addresses
  arrive with the pictures.
- Everything imported lands in the same held-for-review queue as any other
  upload, with the same duplicate check, consent record and limits.

## Checking it works

Sign in at `/email`, go to **Add photos**, press **Import from Google
Photos…**. Expect: a Google window asking for "See the photos you select",
then Google's picker, then a count of what came in. A second import of the same
photos should report them as *already here* rather than adding twins.
