# MOA skip the clean
The user "logs in" with their <mark>room number</mark> and <mark>surname</mark> of who made the reservation to their room.
The system saves the MAC address of the device and authenticates the user. Max 3 devices on fast wifi per room.
The user can choose to have slow internet or fast internet when they skip the clean. The system must remember which devices use what.

# Technical notes
Login page in german and english
From the login page take:
- MAC of the device
- Room number
- Surname

The database will contain a table "Authorized devices" which is structured as:
- device MAC (string)
- room number (string)
- fast mode (bool)
- last update (timestamp)

and a table "Rooms to skip"
- room number (string)
- skip (bool)

Small documentation from RouterOS
"
Available Pages
Main HTML servlet pages, which are shown to the user:

redirect.html - redirects the user to another URL (for example, to the login page)
login.html - login page shown to a user to ask for a username and password. This page may take the following parameters:
    username - username
    password - either plain-text password (in case of PAP authentication) or MD5 hash of chap-id variable, password, and CHAP challenge (in case of CHAP authentication). This value is used as e-mail address for trial users
    dst - original URL requested before the redirect. This will be opened on successful login
    popup - whether to pop-up a status window on successful login
    radius<id> - send the attribute identified with <id> in text string form to the RADIUS server (in case RADIUS authentication is used; lost otherwise)
    radius<id>u - send the attribute identified with <id> in unsigned integer form to the RADIUS server (in case RADIUS authentication is used; lost otherwise)
    radius<id>-<vnd-id> - send the attribute identified with <id> and vendor ID <vnd-id> in text string form to the RADIUS server (in case RADIUS authentication is used; lost otherwise)
    radius<id>-<vnd-id>u - send the attribute identified with <id> and vendor ID <vnd-id> in unsigned integer form to the RADIUS server (in case RADIUS authentication is used; lost otherwise)
md5.js - JavaScript for MD5 password hashing. Used together with http-chap login method
alogin.html - page shown after a client has logged in. It pops-up status page and redirects the browser to the originally requested page (before he/she was redirected to the HotSpot login page)
status.html - status page, shows statistics for the client. It is also able to display advertisements automatically
logout.html - logout page, shown after a user is logged out. Shows final statistics about the finished session. This page may take the following additional parameters:
erase-cookie - whether to erase cookies from the HotSpot server on logout (makes it impossible to log in with cookie next time from the same browser, might be useful in multiuser environments)
error.html - error page, shown on fatal errors only
Some other pages are available as well, if more control is needed:

rlogin.html - page, which redirects the client from some other URL to the login page, if authorization of the client is required to access that URL
rstatus.html - similar to rlogin.html, only in case if the client is already logged in and the original URL is not known
radvert.html - redirects the client to the scheduled advertisement link
flogin.html - shown instead of login.html, if some error has happened (invalid username or password, for example)
fstatus.html - shown instead of redirect, if a status page is requested, but the client is not logged in
flogout.html - shown instead of redirect, if logout page is requested, but the client is not logged in
"