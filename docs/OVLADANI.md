# Site Snapshot – ovládání krok za krokem

Obrázkový průvodce přímo z administrace WordPressu. Čísla v červených kolečkách na snímcích odpovídají krokům
v textu. Podrobnosti (zabezpečení serveru, obnova ze zálohy, řešení problémů) jsou v [kompletním návodu](NAVOD.md).

**Obsah**

1. [Instalace](#1-instalace)
2. [Kde plugin najdete](#2-kde-plugin-najdete)
3. [Vytvoření zálohy](#3-vytvoření-zálohy)
4. [Stažení a smazání zálohy](#4-stažení-a-smazání-zálohy)
5. [Přerušená záloha](#5-přerušená-záloha)
6. [Prohlížeč souborů](#6-prohlížeč-souborů)
7. [Přístupové údaje](#7-přístupové-údaje)
8. [Historie](#8-historie)
9. [Upozornění na nechráněné zálohy](#9-upozornění-na-nechráněné-zálohy)
10. [Odinstalace](#10-odinstalace)

---

## 1. Instalace

Stáhněte **`site-snapshot-X.Y.Z.zip`** z [posledního releasu](https://github.com/JirakJ/site-snapshot/releases/latest)
(soubor pod „Assets“, ne „Source code“). ZIP nerozbalujte.

V administraci otevřete **Pluginy → Přidat plugin → Nahrát plugin**.

![Nahrání pluginu](img/01-instalace-nahrat.jpg)

1. **Vybrat soubor** – vyberte stažený ZIP.
2. **Instalovat**.

![Aktivace pluginu](img/02-instalace-aktivovat.jpg)

3. Po hlášce „Plugin byl úspěšně nainstalován“ klikněte **Aktivovat plugin**.

---

## 2. Kde plugin najdete

![Seznam pluginů s odkazem Otevřít](img/03-pluginy-otevrit.jpg)

4. V seznamu pluginů je u Site Snapshot odkaz **Otevřít**…

![Menu Nástroje → Site Snapshot](img/04-menu-nastroje.jpg)

5. …nebo kdykoli přes menu **Nástroje → Site Snapshot**.

Plugin vidí jen administrátoři (na multisite super admin).

---

## 3. Vytvoření zálohy

Karta **Záloha**:

![Volby zálohy](img/05-zaloha-volby.jpg)

1. **Soubory webu** – celý web (WordPress, šablony, pluginy, média, `wp-config.php`). Vedle je cesta, která se zálohuje.
2. **Databáze** – všechny tabulky tohoto webu.
3. **Všechny tabulky v databázi** – zapněte jen tehdy, když databázi sdílí i jiná aplikace a chcete zálohovat i ji.
4. **Vynechat cache a zálohy jiných pluginů** – doporučeno nechat zapnuté (záloha bude menší a rychlejší).
5. **Vytvořit zálohu**.

![Průběh zálohy](img/06-zaloha-prubeh.jpg)

- Průběh ukazuje procenta, počet souborů a velikost. **Stránku nechte otevřenou** – při pokusu o odchod se
  prohlížeč zeptá.
6. **Zrušit zálohu** ji zastaví a smaže rozpracované soubory.

Po dokončení se stránka sama obnoví.

---

## 4. Stažení a smazání zálohy

![Hotová záloha](img/07-zaloha-stazeni.jpg)

7. **Stáhnout ZIP** – stáhne celou zálohu (soubory webu, `database.sql`, `SITE-INFO.txt` s verzemi a přístupy,
   návod k obnově).
8. **Smazat** – po stažení zálohu ze serveru smažte (obsahuje hesla). Prohlížeč se ještě zeptá, jestli opravdu.
9. **Upozornění** – kliknutím rozbalíte soubory, které se nepodařilo přečíst (typicky kvůli právům). Zbytek
   zálohy je v pořádku.

---

## 5. Přerušená záloha

Když během zálohy zavřete stránku nebo vypadne připojení, nic se neztratí. Do 15 minut se záloha po otevření
karty *Záloha* rozběhne sama. Později je v seznamu jako **Přerušeno**:

![Přerušená záloha s tlačítkem Pokračovat](img/08-zaloha-pokracovat.jpg)

10. **Pokračovat** – záloha naváže od posledního uloženého místa. Přerušená záloha, na kterou se 24 hodin
    nesáhne, se automaticky smaže.

---

## 6. Prohlížeč souborů

Karta **Soubory** funguje jako jednoduchý FTP klient (jen pro čtení a stahování):

![Prohlížeč souborů](img/09-soubory.jpg)

1. Kliknutím na název otevřete složku.
2. **ZIP** – stáhne složku včetně podsložek.
3. Štítek **citlivé** – soubor obsahuje konfiguraci nebo hesla (`wp-config.php`, `.htaccess`, `.env`…).
4. **Stáhnout** – stáhne jeden soubor.
5. **Stáhnout tuto složku jako ZIP** – stáhne právě otevřenou složku.

![Podsložka](img/10-soubory-podslozka.jpg)

1. Drobečková navigace – kliknutím se vrátíte do nadřazené složky.
2. **↑ ..** – o úroveň výš.
3. Zašedlý soubor se štítkem **nelze číst** – PHP k němu nemá práva, nejde stáhnout ani zálohovat
   (stáhněte ho přes FTP).

> Celý web nebo velké složky stahujte raději přes kartu *Záloha* – ta zvládne i velké weby po částech.

---

## 7. Přístupové údaje

Karta **Systém a přístupy** nahoře ukazuje verze a limity WordPressu, PHP, databáze a serveru (viz snímek u kroku 4
níže). Pod nimi jsou přístupové údaje z `wp-config.php`:

![Heslo k databázi](img/11-pristupy-heslo.jpg)

1. **Zobrazit / Skrýt** – odkryje heslo k databázi.
2. **Kopírovat** – zkopíruje heslo do schránky.

FTP a hosting WordPress nezná – doplňte je ručně, ať jsou v každé záloze:

![Formulář FTP a hosting](img/12-pristupy-ftp.jpg)

1. Vyplňte, co znáte: protokol, server, port, uživatele, heslo, cestu k webu, administraci hostingu,
   phpMyAdmin a poznámku.
2. **Zobrazit** – ukáže zadané heslo (kontrola překlepů).

![Uložení přístupů](img/13-pristupy-ulozit.jpg)

3. **Uložit přístupy** – údaje se uloží šifrovaně.

![Potvrzení uložení](img/14-system.jpg)

4. Potvrzení „Přístupové údaje uloženy (šifrovaně)“.

---

## 8. Historie

![Historie](img/15-historie.jpg)

1. Každý řádek: kdy, kdo, z jaké IP adresy a co udělal (záloha, stažení, zobrazení nebo uložení přístupů).
2. **Vymazat historii** – smaže záznamy; samotné vymazání se do historie zapíše.

---

## 9. Upozornění na nechráněné zálohy

Při otevření karty *Záloha* plugin sám otestuje, jestli jdou zálohy stáhnout z internetu bez přihlášení.
Pokud je vše v pořádku, **nic se nezobrazí**. Pokud ne:

![Upozornění na nechráněné zálohy](img/16-ochrana.jpg)

1. Pravidlo pro nginx vygenerované pro váš web – vložte ho do konfigurace serveru nebo ho pošlete podpoře
   hostingu (postup v [návodu, kapitola 4](NAVOD.md#4-zabezpečení-úložiště-záloh-důležité)).
2. **Otestovat znovu** – po úpravě serveru ověří, že je ochrana funkční.

Dokud upozornění nezmizí, mažte zálohy ze serveru hned po stažení.

---

## 10. Odinstalace

**Pluginy → Přehled pluginů** → u Site Snapshot **Deaktivovat**. Deaktivace nic nemaže.

![Smazání pluginu](img/17-odinstalace.jpg)

**Smazat** odstraní plugin **i všechny zálohy na serveru**, uložené přístupy a historii. Zálohy, které chcete
zachovat, si předtím stáhněte.
