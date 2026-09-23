# Changelog

## 0.2.0 – 2026-09-23

- **Samotest ochrany úložiště:** karta Záloha si přes HTTP (jako nepřihlášený návštěvník) zkusí stáhnout
  kontrolní `probe.txt` ze složky záloh. Když to jde, zobrazí červené upozornění s pravidlem pro nginx
  (`location ^~ <uploads>/site-snapshot-`; na multisite `location ~ "/site-snapshot-[a-z0-9]{16,}(/|$)"`).
  Funguje pro jakýkoli server (i nginx před Apachem, OpenResty, CDN); výsledek v cache 24 h / 1 h,
  tlačítko „Otestovat znovu“. Ověřeno: PHP dev server → exposed, Apache 2.4 → protected (403),
  nginx s pravidlem → protected (404 i s `%2D`, `//`, `/./`, `/../` a s regex blokem pro statické soubory),
  nginx bez pravidla → exposed, multisite s přepisy `/shop/wp-content/…` → 404, nedostupný loopback → unknown.
- **IIS:** opravený `web.config` (URL Authorization v `system.webServer/security`); ochranné soubory se při
  aktualizaci pluginu přepíšou, pokud jsou zastaralé.
- **Odinstalace na multisite** maže zálohy, přístupy a historii všech webů sítě (dřív jen hlavního) a nově
  i přechodné záznamy (`sitesnap_*` transienty).
- Filtr `sitesnap_excluded_dirs` umí vynechat i jednotlivé soubory; krok zálohy si vyžádá aspoň 120 s nebo
  `max_execution_time` hostingu, je-li vyšší (dřív napevno 120 s).
- Přehled systému ukazuje `zlib` a jestli je PHP 64bitové.
- **Build** přes `git archive` – ZIP pluginu obsahuje jen verzované soubory. (ZIP u v0.1.0 omylem obsahoval
  lokální metadata nástroje `.claude-flow/`; asset byl nahrazen čistým buildem.)
- **Dokumentace:** kompletní návod [`docs/NAVOD.md`](docs/NAVOD.md) se screenshoty – instalace, zabezpečení,
  FTP přístupy, záloha, obnova, velké weby, řešení problémů, odinstalace.
- Licence GPL-2.0 (`LICENSE`), repozitář je veřejný.
- Z repozitáře odstraněna omylem commitnutá lokální metadata nástrojů (`.claude/`).

## 0.1.0 – 2026-09-23

První verze.

- Záloha souborů + databáze do jednoho ZIPu po krocích (resumable, ZIP64), s `SITE-INFO.txt` / `site-info.json`
  (verze PHP / DB / WP / serveru, přístupové údaje) a návodem k obnově.
- Prohlížeč souborů webu se stažením souboru nebo složky jako ZIP.
- Přehled systému a přístupů, šifrované FTP/hosting údaje.
- Audit log akcí (záloha, stažení, zobrazení přístupů).

Ověřeno na WordPress 7.1.2 / PHP 8.5.7 / MariaDB 13.0.2: `unzip -t` + Python `zipfile` bez chyb, obsah ZIPu
shodný se zdrojem (`diff -r`), obnova `database.sql` do čisté DB → `CHECKSUM TABLE` shodný u všech tabulek
(včetně BLOB/BIT/JSON/NULL/emoji/generovaného sloupce, pohledu a tabulky s ` v názvu), 9 simulovaných pádů
uprostřed zápisu, ZIP64 se souborem 4,5 GB.
