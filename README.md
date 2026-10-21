# CCM — Car Cost Management

Responzivní web pro evidenci provozu auta: tankování, nabíjení a ostatní výdaje. Laravel 13 / PHP 8.4, React 19 / TypeScript, externí MariaDB, Docker Compose. Údaje jednotlivých vlastníků jsou oddělené.

## Aktuální stav

První MVP je nasazené a uživatelem ověřené: založení účtu, přihlášení, přidání vozidla a ruční zadávání tankování i výdajů. Opravena je také kompatibilita identifikátorů požadavků s dočasným provozem přes HTTP.

Nově je připravený **krok 7: CSV kontrola a uložený náhled importu**. Náhled lze prohlížet, filtrovat, přeskočit jednotlivé záznamy nebo zrušit celou dávku. **Do historie tankování ani výdajů zatím nic nezapisuje.** Potvrzení skutečného importu je následující krok.

## Roadmapa

| Stav | Funkcionalita | Poznámka / další práce |
|---|---|---|
| Hotovo | Přihlášení a účty zakládané správcem | Webová session, CSRF a kontrola vlastnictví dat |
| Hotovo | Databázový základ a počáteční číselníky | Přesné desetinné typy, FK, transakce, audit a nové tabulky náhledů |
| Hotovo – základ | Založení vozidla a výchozího média | Editace/archivace vozidla a správa dalších médií zůstávají ve frontě |
| Hotovo – základ | Ruční zadání a editace tankování a výdajů | Revize proti přepsání změn, ochrana proti opakovanému odeslání; pokročilá tankovací pole a vyřazení/obnova ještě nejsou ve formulářích |
| Hotovo | Stránkovaná historie s filtry | Období od–do včetně hranic, měna, kategorie výdaje nebo druh paliva |
| Hotovo | Součty podle měny a druhu události | Stejné filtry jako historie; záporné vratky se započítají jednou |
| Hotovo | Responzivní web a provoz přes HTTP/HTTPS | UUID v4 funguje i bez crypto.randomUUID; pro produkci je cílovým režimem HTTPS |
| Hotovo | Docker image, Compose, deploy a CI | Externí DB_URL, řízené migrace, healthcheck, volitelný oddělený migrační účet |
| Hotovo – krok 7 | CSV validace a náhled SpritMonitor/CCM | UTF-8/BOM, středník, desetinná čárka/tečka, víceřádkové poznámky, dva styly uvozovek, kódy i textové kategorie |
| Hotovo – krok 7 | Uložené dávky, chyby a varování | Původní CSV i řádky se uchovávají; BC nuly podle profilu, SLO→SI, Roads, přesné součty, přeskočení a zrušení náhledu |
| **Další – krok 8** | **Potvrzení importu do historie** | Opětovná validace, transakce, idempotence potvrzení, duplicity vůči existujícím záznamům a vrácení dávky s řešením konfliktů |
| Ve frontě – krok 8a | CSV export | Stejná struktura 22/7 sloupců; ověření zpětného přenosu |
| Ve frontě – krok 9 | Výpočty spotřeby | Plná–plná, částečná/první/neplatná tankování, přerušení historie, více médií a vážené průměry |
| Ve frontě – krok 10 | Plány opakovaných výdajů | Zatím se ukládá jen interval u skutečného výdaje, nikoli budoucí platby |
| Ve frontě – krok 11 | Analýzy, grafy a náklady na vzdálenost | Kategorie a skupiny nákladů, doložené období/vzdálenost, rozdíl nákupu a spotřeby |
| Ve frontě – dokončení kroků 3–6 | Správa historie a vozidel | Další média, archivace, vyřazení/obnova, pokročilá pole a zobrazení auditu |
| Ve frontě – technické dokončení | Mobilní API a provozní ověření | Úplný OpenAPI kontrakt, tokeny zařízení, další statická analýza, cílová MariaDB LTS a finální přejímka |
| Později | Rozšíření | Nativní iOS/Android klienti, účtenky, sdílení vozidel, notifikace, kurzy a přímá synchronizace |

## Importní náhled v této verzi

V záložce **CSV import** vyberte vozidlo, typ souboru, profil, desetinný oddělovač a u tankování odpovídající médium. Jednotky je nutné potvrdit: v CSV samotném nejsou uvedené. Jedna dávka má nejvýše **2 MiB a 5 000 záznamů**; náhled je stránkovaný po 25 záznamech.

Chyby se neopravují odhadem. Lze záznam přeskočit, zahrnout zpět nebo nahrát opravený soubor s novým náhledem. Původní data zůstávají zachovaná. Součty zahrnují pouze záznamy bez chyb, které nejsou přeskočené, a nikdy nesčítají různé měny. Shody uvnitř souboru a opakované nahrání se označují; porovnání s finanční historií přijde v kroku 8. Zrušený náhled není fyzicky smazaný.

Současná omezení: bez převodu jednotek, jeden zvolený proud média na soubor, bez ruční editace buněk uloženého náhledu. CSV náhled není dokončený import a neobsahuje tlačítko pro zápis do historie.

## Spuštění a aktualizace

[Spuštění, konfigurace a Docker deploy](docs/program/spusteni.md). Připojení přes DB_URL v chráněném env souboru mimo repozitář; produkční Compose nezakládá DB server. Tato verze přidává novou migraci tabulek importních náhledů, kterou deploy skript provede před startem nové aplikace.

## Ověření

- `npm run test:client` — klientské regresní testy UUID, včetně HTTP fallbacku.
- `npm run build` — TypeScript a produkční sestavení webu.
- `vendor/bin/pint --test` — PHP styl.
- `vendor/bin/phpunit` — oprávnění, transakce, idempotence, revize, filtry a CSV náhledy; integrační běh proti MariaDB 10.5.28.

Testy používají výhradně samostatnou testovací DB (RefreshDatabase data obnovuje). Soukromé CSV přílohy nejsou součástí repozitáře; volitelné CCM_EXAMPLE_FILES umožní ověřit skutečné příklady mimo Git. Kontrolní základ: 17 tankování / 35 016,60 CZK + 236,43 EUR a 24 výdajů / 560 093,94 CZK. Běžné testy a CI používají syntetická data.

## Dokumentace

- [Cílový koncept](docs/cilovy-koncept.md)
- [Postup implementace a milníky](docs/postup-implementace.md)
- [Technická koncepce](docs/technicka-koncepce.md)
- [Dokumentace programu](docs/program/README.md)
- [Případy použití](docs/program/pripady-pouziti.md)
- [CSV formát pro import a export](docs/program/csv-import-export.md)
