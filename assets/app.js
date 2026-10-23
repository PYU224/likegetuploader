// likegetuploader
(function () {
  'use strict';

  // コピー用欄はクリックで全選択
  document.querySelectorAll('input.copy').forEach(function (el) {
    el.addEventListener('focus', function () { el.select(); });
  });

  // #upload で来たらフォームを開く
  var det = document.getElementById('upload');
  if (det && location.hash === '#upload') det.open = true;
  window.addEventListener('hashchange', function () { if (det && location.hash === '#upload') det.open = true; });

  var form = document.getElementById('upform');
  if (!form) return;
  var input = document.getElementById('file');
  var drop = document.getElementById('drop');
  var queue = document.getElementById('queue');
  var chunkSize = parseInt(form.dataset.chunk, 10) || 8388608;
  var maxSize = parseInt(form.dataset.max, 10) || 0;
  var csrf = form.elements.csrf.value;

  ['dragenter', 'dragover'].forEach(function (ev) {
    drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('over'); });
  });
  ['dragleave', 'drop'].forEach(function (ev) {
    drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('over'); });
  });
  drop.addEventListener('drop', function (e) {
    if (e.dataTransfer && e.dataTransfer.files.length) input.files = e.dataTransfer.files;
  });

  function fmt(b) {
    var u = ['B', 'KB', 'MB', 'GB'], i = 0;
    while (b >= 1024 && i < 3) { b /= 1024; i++; }
    return (i ? b.toFixed(1) : b) + ' ' + u[i];
  }

  function post(data, onProgress) {
    return new Promise(function (resolve, reject) {
      var xhr = new XMLHttpRequest();
      xhr.open('POST', 'upload.php');
      xhr.setRequestHeader('Accept', 'application/json');
      xhr.setRequestHeader('X-CSRF', csrf);
      if (onProgress) xhr.upload.onprogress = function (e) { if (e.lengthComputable) onProgress(e.loaded); };
      xhr.onload = function () {
        var res;
        try { res = JSON.parse(xhr.responseText); } catch (e) {
          return reject(new Error(xhr.status === 413 ? '1回の送信が大きすぎます（サーバー設定を確認）' : 'サーバーエラー (' + xhr.status + ')'));
        }
        if (res.ok) resolve(res); else reject(new Error(res.error || 'エラー'));
      };
      xhr.onerror = function () { reject(new Error('通信エラー')); };
      xhr.send(data);
    });
  }

  function fd(obj) {
    var f = new FormData();
    Object.keys(obj).forEach(function (k) { f.append(k, obj[k]); });
    f.append('csrf', csrf);
    return f;
  }

  // Turnstile: 一度通れば一定時間は再確認不要（サーバー側で記憶）
  function tsToken() {
    var el = form.querySelector('[name="cf-turnstile-response"]');
    return el ? el.value : '';
  }
  function tsPassed() {
    var box = form.querySelector('.ts-box');
    if (box) box.style.display = 'none';
  }
  function tsReset() {
    if (window.turnstile && form.querySelector('.ts-box')) { try { window.turnstile.reset(); } catch (e) {} }
  }

  async function sendOne(file, li) {
    var bar = li.querySelector('.bar i');
    var stat = li.querySelector('.stat');
    if (maxSize && file.size > maxSize) throw new Error('大きすぎます（上限 ' + fmt(maxSize) + '）');
    var init = await post(fd({
      action: 'init', name: file.name, size: file.size,
      comment: form.elements.comment.value,
      delkey: form.elements.delkey.value,
      dlpass: form.elements.dlpass.value,
      uppass: form.elements.uppass ? form.elements.uppass.value : '',
      'cf-turnstile-response': tsToken()
    }));
    tsPassed();
    var size = init.chunk || chunkSize;
    var sent = 0;
    for (var i = 0; sent < file.size; i++) {
      var blob = file.slice(sent, sent + size);
      var f = fd({ action: 'chunk', token: init.token, index: i });
      f.append('blob', blob, 'blob');
      var base = sent;
      await post(f, function (loaded) {
        var p = Math.min(1, (base + loaded) / file.size);
        bar.style.width = (p * 100).toFixed(1) + '%';
        stat.textContent = Math.floor(p * 100) + '%';
      });
      sent += blob.size;
    }
    stat.textContent = '確認中…';
    return post(fd({ action: 'finish', token: init.token }));
  }

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    var files = Array.prototype.slice.call(input.files);
    if (!files.length) return;
    var box = form.querySelector('.ts-box');
    if (box && box.style.display !== 'none' && !tsToken()) {
      alert('「人間であることを確認」のチェックが終わるまでお待ちください。');
      return;
    }
    var btn = form.querySelector('button[type=submit]');
    btn.disabled = true;
    var okCount = 0;
    for (var k = 0; k < files.length; k++) {
      var file = files[k];
      var li = document.createElement('li');
      li.innerHTML = '<span class="n"></span> <small>(' + fmt(file.size) + ')</small> <span class="stat">待機中</span><div class="bar"><i></i></div>';
      li.querySelector('.n').textContent = file.name;
      queue.appendChild(li);
      try {
        var res = await sendOne(file, li);
        li.classList.add('done');
        li.querySelector('.bar i').style.width = '100%';
        var stat = li.querySelector('.stat');
        stat.textContent = '';
        var a = document.createElement('a');
        a.href = res.link;
        a.textContent = '完了 → No.' + res.id;
        stat.appendChild(a);
        okCount++;
      } catch (err) {
        var s = li.querySelector('.stat');
        s.className = 'stat err';
        s.textContent = '失敗: ' + err.message;
        if (/人間/.test(err.message)) { tsReset(); break; }
      }
    }
    btn.disabled = false;
    input.value = '';
    if (okCount) {
      var r = document.createElement('li');
      r.innerHTML = '<a href="./">一覧を更新して確認する</a>';
      queue.appendChild(r);
    }
  });
})();
