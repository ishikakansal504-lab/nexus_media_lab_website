/* Shared pieces for the portfolio pages: work cards, file chips, formatting. */
var KIND_ICON = { image: '🖼️', pdf: '📄', video: '🎬', doc: '📎' };
function fmtSize(n) { return n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB'; }
function fmtDay(s) { if (!s) return ''; var d = new Date(s + 'T00:00:00'); return isNaN(d) ? s : d.toLocaleDateString([], { dateStyle: 'medium' }); }
function nl2br(s) { return esc(s).replace(/\n/g, '<br>'); }
function safeUrl(u) { return /^https?:\/\//i.test(u) ? u : '#'; }

var WORKFILES = {};
/* File viewer: clicking a file opens it in a window with a Download button, instead of downloading it straight away. */
function openViewer(workId, fileId) {
  var files = WORKFILES[workId] || [], i = files.map(function (f) { return f.id; }).indexOf(fileId);
  if (i < 0) return;
  var d = $('viewer');
  if (!d) { d = document.createElement('dialog'); d.id = 'viewer'; d.className = 'viewer'; document.body.appendChild(d); }
  if (!d._wired) { d._wired = true; d.addEventListener('click', function (e) { if (e.target === d) d.close(); }); d.addEventListener('close', function () { d.innerHTML = ''; }); }
  var f = files[i], url = 'api/file.php?id=' + f.id, body;
  if (f.kind === 'image') body = '<img src="' + url + '" alt="' + esc(f.name) + '">';
  else if (f.kind === 'video') body = '<video src="' + url + '" controls autoplay></video>';
  else if (f.kind === 'pdf') body = '<iframe src="' + url + '" title="' + esc(f.name) + '"></iframe>';
  else body = '<div style="text-align:center;padding:30px"><div style="font-size:3rem">' + KIND_ICON[f.kind] + '</div><h3>' + esc(f.name) + '</h3><p class="muted">This kind of file cannot be shown in the browser. Press Download to save it and open it on your computer.</p></div>';
  d.innerHTML = '<div class="vbar"><b title="' + esc(f.name) + '">' + KIND_ICON[f.kind] + ' ' + esc(f.name) + ' <span class="muted">' + fmtSize(f.size) + '</span></b>' +
    '<div class="vnav">' + (files.length > 1 ? '<button class="btn ghost sm" data-vn="-1">&larr;</button><span class="muted" style="align-self:center">' + (i + 1) + ' / ' + files.length + '</span><button class="btn ghost sm" data-vn="1">&rarr;</button>' : '') +
    '<a class="btn sm" href="' + url + '&download=1" download>Download</a><button class="btn ghost sm" data-vc="1">Close</button></div></div><div class="vbody">' + body + '</div>';
  d.querySelector('[data-vc]').onclick = function () { d.close(); };
  [].forEach.call(d.querySelectorAll('[data-vn]'), function (b) { b.onclick = function () { openViewer(workId, files[(i + +b.dataset.vn + files.length) % files.length].id); }; });
  if (!d.open) d.showModal();
}
document.addEventListener('click', function (e) {
  var a = e.target.closest('a[data-fv]'); if (!a) return;
  e.preventDefault(); openViewer(+a.dataset.wk, +a.dataset.fv);
});
/* One work card. opts.owner = show the owner's name, opts.actions = show Edit/Delete buttons */
function workCard(w, opts) {
  opts = opts || {};
  WORKFILES[w.id] = w.files;
  var imgs = w.files.filter(function (f) { return f.kind === 'image'; });
  var others = w.files.filter(function (f) { return f.kind !== 'image'; });
  var gallery = imgs.length ? '<div class="gallery g' + Math.min(imgs.length, 3) + '">' + imgs.map(function (f) {
    return '<a href="api/file.php?id=' + f.id + '" data-fv="' + f.id + '" data-wk="' + w.id + '"><img src="api/file.php?id=' + f.id + '" alt="' + esc(f.name) + '" loading="lazy"></a>';
  }).join('') + '</div>' : '';
  var chips = others.map(function (f) {
    return '<a class="chip" href="api/file.php?id=' + f.id + '" data-fv="' + f.id + '" data-wk="' + w.id + '">' + KIND_ICON[f.kind] + ' ' + esc(f.name) + ' <small>' + fmtSize(f.size) + '</small></a>';
  }).join('');
  var link = w.link_url ? '<a class="chip link" href="' + esc(safeUrl(w.link_url)) + '" target="_blank" rel="noopener noreferrer">🔗 ' + esc(w.link_url.replace(/^https?:\/\//i, '').slice(0, 48)) + '</a>' : '';
  return '<article class="work card" data-id="' + w.id + '">' + gallery +
    '<div class="meta"><span class="badge cat">' + esc(w.category) + '</span>' + (w.work_date ? '<span class="muted">' + esc(fmtDay(w.work_date)) + '</span>' : '') + '</div>' +
    '<h3>' + esc(w.title) + '</h3>' +
    (opts.owner ? '<div class="muted">by <b>' + esc(w.owner_name) + '</b> · ' + esc(w.owner_email) + '</div>' : '') +
    (w.description ? '<p>' + nl2br(w.description) + '</p>' : '') +
    ((chips || link) ? '<div class="chips">' + link + chips + '</div>' : '') +
    (opts.actions ? '<div class="acts"><button class="btn ghost sm" data-a="edit" data-id="' + w.id + '">Edit</button><button class="btn danger sm" data-a="del" data-id="' + w.id + '">Delete</button></div>' : '') +
    '</article>';
}
function skillChips(s) {
  return s.split(',').map(function (x) { return x.trim(); }).filter(Boolean).map(function (x) { return '<span class="chip">' + esc(x) + '</span>'; }).join('');
}
