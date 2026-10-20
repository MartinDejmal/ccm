# ccm
Car Cost Management web app

## Dokumentace

[Cílový koncept aplikace](docs/cilovy-koncept.md) — rozsah první verze, datový model, počáteční číselníky, CSV import SpritMonitor.de a pravidla výpočtů.

[Postup implementace](docs/postup-implementace.md) — doporučené kroky, závislosti, milníky a podmínky dokončení první verze.

[Dokumentace programu](docs/program/README.md) — popis výsledných funkcí a společného CSV formátu pro import a export.

[Technická koncepce](docs/technicka-koncepce.md) — doporučené technologie, API, responzivní web, MariaDB a návrh nasazení v Dockeru.

## První MVP

Laravel 13 / PHP 8.4, React 19 / TypeScript, externí MariaDB. Přihlášení, vozidla, tankování, ostatní výdaje, editace historie a součty podle měn.

[Spuštění, migrace a Docker deploy](docs/program/spusteni.md). Připojení se nastavuje přes DB_URL v chráněném env souboru mimo repozitář; produkční Compose nezakládá DB server.
