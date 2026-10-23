#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
uploader.jp (getuploader.com) のアップローダーを丸ごと保存するツール
※ uploader.jp の運営とは無関係の非公式ツールです。自分が管理する・関わるアップローダーの保全目的で使ってください。
  保存したファイルの著作権は各投稿者にあります。他の場所で再公開する場合は投稿者の許可を取ってください。

  python getuploader_backup.py vahren ./vahren_backup
  python getuploader_backup.py https://ux.getuploader.com/vahren/ ./vahren_backup --delay 3
  python getuploader_backup.py vahren ./vahren_backup --limit 5   (お試しで5件だけ)

- Python 3.8 以上・標準ライブラリのみ (Windows / macOS / Linux)
- 一覧の全ページを巡回 → 各ファイルの詳細ページ → 正規の「ダウンロード」手順で取得
- 詳細ページのMD5と照合し、一致したものだけ保存
- 途中で止めても、もう一度同じコマンドを実行すれば続きから再開
- 出力:
    <出力先>/files/<番号>_<ファイル名>   … 実ファイル
    <出力先>/meta.jsonl                  … 1行1ファイルのメタ情報（likegetuploader の tools/import.php で取り込める）
    <出力先>/failed.jsonl                … 取得できなかったもの（パスワード付きなど）
"""
import argparse
import hashlib
import html
import http.cookiejar
import json
import os
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timedelta, timezone

JST = timezone(timedelta(hours=9))
UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36"


def log(*a):
    print(time.strftime("[%H:%M:%S]"), *a, flush=True)


class Client:
    def __init__(self, delay):
        self.delay = delay
        self.cj = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.cj))
        self.last = 0.0

    def _wait(self):
        d = self.delay - (time.time() - self.last)
        if d > 0:
            time.sleep(d)
        self.last = time.time()

    def open(self, url, data=None, referer=None, tries=5):
        for i in range(tries):
            self._wait()
            req = urllib.request.Request(url, data=data, headers={"User-Agent": UA, "Accept-Language": "ja"})
            if referer:
                req.add_header("Referer", referer)
            try:
                return self.opener.open(req, timeout=120)
            except urllib.error.HTTPError as e:
                if e.code in (404, 410):
                    raise
                wait = min(300, 15 * (2 ** i))
                log(f"  HTTP {e.code} … {wait}秒待って再試行 ({i + 1}/{tries})")
                time.sleep(wait)
            except (urllib.error.URLError, TimeoutError, ConnectionError) as e:
                wait = min(300, 15 * (2 ** i))
                log(f"  通信エラー {e} … {wait}秒待って再試行 ({i + 1}/{tries})")
                time.sleep(wait)
        raise RuntimeError(f"取得失敗: {url}")

    def text(self, url, data=None, referer=None):
        with self.open(url, data, referer) as r:
            return r.read().decode("utf-8", "replace")


def strip_tags(s):
    s = re.sub(r"<br\s*/?>", "\n", s, flags=re.I)
    s = re.sub(r"<[^>]+>", "", s)
    return html.unescape(s).strip()


def detail_field(page, label):
    m = re.search(r"<th[^>]*>\s*" + re.escape(label) + r"\s*</th>\s*<td[^>]*>(.*?)</td>", page, re.S)
    return strip_tags(m.group(1)) if m else ""


def safe_name(name):
    name = re.sub(r'[\\/:*?"<>|\x00-\x1f]', "_", name).strip(" .")
    return (name or "file")[:150]


def parse_target(t):
    m = re.match(r"https?://(\w+)\.getuploader\.com/([^/?#]+)", t)
    if m:
        return m.group(1), m.group(2)
    return "uu", t.strip("/")


def main():
    ap = argparse.ArgumentParser(description="uploader.jp アップローダー保存ツール")
    ap.add_argument("target", help="アップローダー名 (例: vahren) または URL")
    ap.add_argument("outdir", help="保存先フォルダ")
    ap.add_argument("--delay", type=float, default=3.0, help="リクエスト間隔(秒) 既定3")
    ap.add_argument("--list-only", action="store_true", help="一覧情報だけ保存してファイルは取らない")
    ap.add_argument("--limit", type=int, default=0, help="お試し用: この件数だけ取得して終了")
    args = ap.parse_args()
    if args.delay < 1.0:
        args.delay = 1.0  # 相手サーバーに負荷をかけないよう、1秒未満にはしない

    sub, name = parse_target(args.target)
    base = f"https://{sub}.getuploader.com/{name}"
    os.makedirs(os.path.join(args.outdir, "files"), exist_ok=True)
    meta_path = os.path.join(args.outdir, "meta.jsonl")
    fail_path = os.path.join(args.outdir, "failed.jsonl")
    list_path = os.path.join(args.outdir, "list.json")

    done = set()
    if os.path.exists(meta_path):
        with open(meta_path, encoding="utf-8") as f:
            for line in f:
                if line.strip():
                    done.add(json.loads(line)["id"])
    log(f"対象: {base}  保存済み: {len(done)}件")

    c = Client(args.delay)

    # ---- 1. 一覧を全ページ巡回
    rows = {}
    if os.path.exists(list_path) and done:
        with open(list_path, encoding="utf-8") as f:
            rows = {int(k): v for k, v in json.load(f).items()}
        log(f"一覧は前回分を使用 ({len(rows)}件)。最新分だけ確認します")
    page_no = 1
    while True:
        url = f"{base}/index/date/desc/{page_no}"
        page = c.text(url)
        if "年齢確認" in page and "download/" not in page:
            log("年齢確認ページが出ました。このツールは全年齢アップローダー向けです。")
            break
        found = 0
        new = 0
        for tr in re.findall(r"<tr[^>]*>(.*?)</tr>", page, re.S):
            m = re.search(r'/' + re.escape(name) + r'/download/(\d+)"', tr)
            if not m:
                continue
            tds = re.findall(r"<td[^>]*>(.*?)</td>", tr, re.S)
            fid = int(m.group(1))
            found += 1
            if fid not in rows:
                new += 1
            rows[fid] = {
                "id": fid,
                "name": strip_tags(tds[0]) if tds else "",
                "comment_short": strip_tags(tds[1]) if len(tds) > 1 else "",
                "original": strip_tags(tds[2]) if len(tds) > 2 else "",
                "size_text": strip_tags(tds[3]) if len(tds) > 3 else "",
                "date_short": strip_tags(tds[4]) if len(tds) > 4 else "",
                "downloads": int(strip_tags(tds[5]) or 0) if len(tds) > 5 and strip_tags(tds[5]).isdigit() else 0,
            }
        log(f"一覧 {page_no}ページ: {found}件 (新規 {new})")
        if found == 0 or (done and new == 0):
            break
        page_no += 1
    with open(list_path, "w", encoding="utf-8") as f:
        json.dump(rows, f, ensure_ascii=False, indent=1)
    log(f"一覧合計: {len(rows)}件 → {list_path}")
    if args.list_only:
        return

    # ---- 2. 各ファイルを取得（古い順）
    todo = [i for i in sorted(rows) if i not in done]
    if args.limit:
        todo = todo[:args.limit]
    log(f"これから取得: {len(todo)}件")
    for n, fid in enumerate(todo, 1):
        row = rows[fid]
        durl = f"{base}/download/{fid}"
        try:
            page = c.text(durl)
        except urllib.error.HTTPError as e:
            log(f"[{n}/{len(todo)}] #{fid} 詳細ページ HTTP {e.code} → スキップ")
            with open(fail_path, "a", encoding="utf-8") as f:
                f.write(json.dumps({**row, "reason": f"http {e.code}"}, ensure_ascii=False) + "\n")
            continue

        info = {
            "id": fid,
            "name": detail_field(page, "ファイル") or row["name"],
            "comment": detail_field(page, "コメント"),
            "original": detail_field(page, "オリジナル") or row["original"],
            "size_text": detail_field(page, "容量") or row["size_text"],
            "date": detail_field(page, "日時"),
            "downloads": int(detail_field(page, "ダウンロード") or row["downloads"] or 0),
        }
        m = re.search(r'MD5</label>\s*<input[^>]*value="([0-9a-f]{32})"', page)
        info["md5"] = m.group(1) if m else ""
        try:
            info["created_at"] = int(datetime.strptime(info["date"], "%Y/%m/%d %H:%M:%S").replace(tzinfo=JST).timestamp())
        except ValueError:
            info["created_at"] = 0

        tm = re.search(r'name="token" value="([^"]+)"', page)
        if not tm:
            reason = "password" if re.search(r'type="password"', page) else "no token"
            log(f"[{n}/{len(todo)}] #{fid} {info['name']} → 取得不可 ({reason})")
            with open(fail_path, "a", encoding="utf-8") as f:
                f.write(json.dumps({**info, "reason": reason}, ensure_ascii=False) + "\n")
            continue

        body = urllib.parse.urlencode({"token": tm.group(1), "yes": "ダウンロード"}).encode()
        page2 = c.text(durl, data=body, referer=durl)
        lm = re.search(r'href="(https://download\w*\.getuploader\.com/g/[^"]+)"', page2)
        if not lm:
            log(f"[{n}/{len(todo)}] #{fid} ダウンロードリンクが見つからない → スキップ")
            with open(fail_path, "a", encoding="utf-8") as f:
                f.write(json.dumps({**info, "reason": "no link"}, ensure_ascii=False) + "\n")
            continue
        furl = html.unescape(lm.group(1))

        stored = f"{fid}_{safe_name(info['original'] or info['name'])}"
        dest = os.path.join(args.outdir, "files", stored)
        tmp = dest + ".part"
        h = hashlib.md5()
        size = 0
        with c.open(furl, referer=durl) as r, open(tmp, "wb") as out:
            while True:
                chunk = r.read(1 << 20)
                if not chunk:
                    break
                h.update(chunk)
                out.write(chunk)
                size += len(chunk)
        md5 = h.hexdigest()
        if info["md5"] and md5 != info["md5"]:
            os.remove(tmp)
            log(f"[{n}/{len(todo)}] #{fid} MD5不一致 → スキップ（再実行で再挑戦）")
            continue
        os.replace(tmp, dest)
        info.update({"size": size, "md5": md5, "stored": stored})
        with open(meta_path, "a", encoding="utf-8") as f:
            f.write(json.dumps(info, ensure_ascii=False) + "\n")
        done.add(fid)
        log(f"[{n}/{len(todo)}] #{fid} {info['name']} ({info['size_text']}) OK")

    log(f"完了: 保存 {len(done)}件 / 一覧 {len(rows)}件")
    if os.path.exists(fail_path):
        log(f"取得できなかったもの: {fail_path}")


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        log("中断しました。同じコマンドで続きから再開できます。")
        sys.exit(1)
