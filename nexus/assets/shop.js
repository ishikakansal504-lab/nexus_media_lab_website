/* Shared helpers for the shop and the admin pages: menus, money format, page guard. */
function inr(n) {
  n = Number(n) || 0;
  var frac = Math.abs(Math.round(n * 100) % 100) !== 0;
  return (n < 0 ? '-' : '') + '₹' + Math.abs(n).toLocaleString('en-IN', { minimumFractionDigits: frac ? 2 : 0, maximumFractionDigits: 2 });
}
var NAVS = {
  admin: [['index.html', 'Website'], ['admin.html', 'Users'], ['admin-desk.html', 'Staff desk'], ['admin-orders.html', 'Orders'], ['admin-reports.html', 'Reports'], ['admin-services.html', 'Services'], ['all-work.html', 'All work'], ['admin-settings.html', 'Settings'], ['dashboard.html', 'My account']],
  customer: [['index.html', 'Website'], ['shop.html', 'Shop'], ['cart.html', 'Cart'], ['orders.html', 'My orders'], ['dashboard.html', 'My account']],
  employee: [['index.html', 'Website'], ['employee.html', 'My workspace'], ['portfolio.html', 'My portfolio'], ['portfolio.html#add', 'Add work']]
};
var BIZ_PHONE = '917982845849', BIZ_NAME = 'Nexus Media Labs';
// Pages a visitor is sent back to after logging in. Only these names are accepted (no outside links).
function nextPage() { var m = /[?&]next=((?:shop|cart|orders)\.html)(?:&|$)/.exec(location.search); return m ? m[1] : ''; }
function loginUrl(next, need) { return 'login.html?' + (next ? 'next=' + next + '&' : '') + (need ? 'need=1' : ''); }
// The menu a visitor who is not logged in sees: the shop and cart are always there.
function buildPublicNav() {
  var el = $('nav'); if (!el) return;
  var here = location.pathname.split('/').pop() || 'index.html';
  el.innerHTML = [['index.html', 'Website'], ['shop.html', 'Shop'], ['cart.html', 'Cart']].map(function (l) {
    return '<a href="' + (l[0] === 'cart.html' ? loginUrl('cart.html', 1) : l[0]) + '"' + (l[0] === here ? ' class="on"' : '') + '>' + l[1] + '</a>';
  }).join('') + '<a class="btn sm auto" href="' + loginUrl(here === 'shop.html' ? 'shop.html' : '', 0) + '">Log in</a>';
}
// Green tick in a circle (payment done) or an amber clock in a circle (waiting).
function tickHtml(kind, small) {
  if (kind === 'wait') return '<span class="tick wait' + (small ? ' sm' : '') + '"><svg viewBox="0 0 64 64" aria-hidden="true"><circle class="c" cx="32" cy="32" r="28"/><path class="k" d="M32 18v15l9 6"/></svg></span>';
  return '<span class="tick' + (small ? ' sm' : ' pop') + '"><svg viewBox="0 0 64 64" aria-hidden="true"><circle class="c" cx="32" cy="32" r="28"/><path class="k" d="M19 33l9 9 17-19"/></svg></span>';
}
// The confirmation text used for the WhatsApp links. o = one order as sent by api/orders.php.
function waText(o) {
  var l = ['Hello ' + (o.customer_name || '') + ',', '', 'Your order ' + o.order_no + ' at ' + BIZ_NAME + ' is ' + (o.payment_status === 'paid' ? 'confirmed and PAID.' : 'placed. Payment: ' + o.payment_label + ' (pending).'), ''];
  o.items.forEach(function (i) { l.push('- ' + i.name + ' x ' + i.qty + ' = ' + inr(i.line_total)); });
  l.push('', 'Total: ' + inr(o.total));
  if (o.emi_months) l.push('EMI: ' + inr(o.emi_monthly) + ' a month for ' + o.emi_months + ' months');
  l.push('', 'Delivery: ' + [o.address_line, o.city, o.state].filter(Boolean).join(', ') + (o.pincode ? ' - ' + o.pincode : ''), 'Thank you!');
  return l.join('\n');
}
function waLink(phone10, text) { return 'https://wa.me/' + (phone10.length === 10 ? '91' + phone10 : phone10) + '?text=' + encodeURIComponent(text); }
function buildNav(user, cartCount) {
  var el = $('nav'); if (!el) return;
  var here = location.pathname.split('/').pop() || 'index.html';
  el.innerHTML = (NAVS[user.role] || []).map(function (l) {
    var badge = l[0] === 'cart.html' ? '<span class="cartbadge' + (cartCount ? '' : ' zero') + '" id="cartBadge">' + (cartCount || '') + '</span>' : '';
    return '<a href="' + l[0] + '"' + (l[0] === here ? ' class="on"' : '') + '>' + l[1] + badge + '</a>';
  }).join('') + '<button class="btn ghost sm" onclick="doLogout()">Log out</button>';
}
function setCartBadge(n) { var b = $('cartBadge'); if (!b) return; b.textContent = n || ''; b.className = 'cartbadge' + (n ? '' : ' zero'); }
// Checks who is logged in. roles = allowed roles. Sends other people away. Returns the "me" answer or null.
async function guard(roles) {
  var me = await whoami();
  if (!me.ok || !me.user) {
    var here = location.pathname.split('/').pop();
    if (roles && roles.length === 1 && roles[0] !== 'customer') { location.replace('login.html?as=' + roles[0]); return null; }
    location.replace(loginUrl(/^(shop|cart|orders)\.html$/.test(here) ? here : '', here === 'cart.html' || here === 'orders.html')); return null;
  }
  if (roles && roles.indexOf(me.user.role) < 0) { location.replace('dashboard.html'); return null; }
  buildNav(me.user, me.cart_count);
  return me;
}
function toast(text, bad) {
  var t = $('toast'); if (!t) { t = document.createElement('div'); t.id = 'toast'; t.className = 'toast'; document.body.appendChild(t); }
  t.textContent = text; t.className = 'toast show' + (bad ? ' bad' : '');
  clearTimeout(toast._t); toast._t = setTimeout(function () { t.className = 'toast' + (bad ? ' bad' : ''); }, 2400);
}
function dayStr(d) { return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }
function csvDownload(name, rows) {
  var text = rows.map(function (r) { return r.map(function (c) { c = c == null ? '' : String(c); return /[",\n]/.test(c) ? '"' + c.replace(/"/g, '""') + '"' : c; }).join(','); }).join('\n');
  var a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['﻿' + text], { type: 'text/csv' })); a.download = name; document.body.appendChild(a); a.click(); a.remove();
}
