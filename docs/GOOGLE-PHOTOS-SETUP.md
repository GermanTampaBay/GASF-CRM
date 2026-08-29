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

## 3. Add the JavaScript origin (NOT a redirect URI)

**APIs & Services → Credentials →** the existing OAuth 2.0 Client ID → under
**Authorised JavaScript origins**, add:

```
https://germantampabay.com
```

Origin only — no path, no trailing slash.

**There is no redirect URI to add, and an earlier version of this file was wrong
to ask for one.** The obvious build sends the volunteer to Google and takes a
code back at a callback URL, and that cannot work on this host: Google returns
`scope=https://www.googleapis.com/auth/...` on the callback, and a full URL in a
query string trips this server mod_security rule, which answers **406 Not
Acceptable** before WordPress is reached. Measured, not guessed - the same
callback answers 200 with a code and a state, and 406 the moment the scope is
added. Sign-in is unaffected only because its scopes (`openid email profile`)
are bare words rather than URLs.

mod_security cannot be relaxed for one path here either: `SecRuleEngine` in
.htaccess makes the server answer 500 on this host.

So the token is obtained in the browser with Google Identity Services and
posted to the site, which checks with Google that it belongs to this client and
covers only the picker scope before storing it. Nothing travels in a query
string, so there is nothing for mod_security to refuse.

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
  upload, with the same duplicate check, consent record, and limits.

## Picking holds; Upload saves

Choosing photos in Google's picker does **not** save them. They arrive in the
same waiting list as files dragged in, and nothing is downloaded or written
until **Upload** is pressed — at which point the server fetches them one at a
time and files each one under the date, place, event, and permission the form
says *then*.

That ordering is the point. The batch form is what you fill in while things sit
in the list, so a version that saved on the spot described every photo with an
empty form, and gave no chance to notice that a picked date was the wrong
evening.

The held list is a set of references Google honours for about an hour, kept on
the server and keyed to the volunteer who picked them. After that, Upload
reports that the permission has expired and the button asks Google again.

## Checking it works

Sign in at `/email`, go to **Add photos**, press **Import from Google
Photos…**. Expect: a Google window asking for "See the photos you select", then
Google's picker, then rows appearing in the list marked *Google Photos* with the
date each was taken — and **Upload** becoming available. Nothing should be in
the library until you press it. Picking the same photos twice should report them
as *already waiting in the list*; uploading them twice should report them as
duplicates rather than adding twins.
