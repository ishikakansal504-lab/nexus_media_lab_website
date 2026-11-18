/* Small helper used by login, signup, dashboard and admin pages. */
var API = { csrf: '' };
var NET_ERR = 'Cannot reach the server. Open this site through XAMPP (http://localhost/nexus-media-labs/), not by double-clicking the file.';

async function call(path, data) {
  var opts = { credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
  if (data !== undefined) {
    opts.method = 'POST';
    opts.headers['Content-Type'] = 'application/json';
    opts.headers['X-CSRF-Token'] = API.csrf;
    opts.body = JSON.stringify(data);
  }
  var res, out;
  try { res = await fetch('api/' + path, opts); out = await res.json(); }
  catch (e) { return { ok: false, error: NET_ERR }; }
  if (out.csrf) API.csrf = out.csrf;
  return out;
}
async function whoami() { return call('me.php'); }
function $(id) { return document.getElementById(id); }
function showMsg(id, text, good) {
  var el = $(id); el.textContent = text || '';
  el.className = 'alert ' + (good ? 'good' : 'bad') + (text ? ' show' : '');
}
function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }
function fmtDate(s) { if (!s) return 'Never'; var d = new Date(s.replace(' ', 'T')); return isNaN(d) ? s : d.toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }); }
function wirePwToggles() {
  document.querySelectorAll('.pw button').forEach(function (b) {
    b.addEventListener('click', function () {
      var i = b.parentNode.querySelector('input'); var show = i.type === 'password';
      i.type = show ? 'text' : 'password'; b.textContent = show ? 'Hide' : 'Show';
    });
  });
}
async function doLogout() { await call('logout.php', {}); location.href = 'login.html'; }

/* Sends a form with files (multipart). Used for adding/updating work. */
async function callForm(path, formData) {
  var res, out;
  try {
    res = await fetch('api/' + path, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': API.csrf, 'Accept': 'application/json' }, body: formData });
    out = await res.json();
  } catch (e) { return { ok: false, error: NET_ERR }; }
  return out;
}
