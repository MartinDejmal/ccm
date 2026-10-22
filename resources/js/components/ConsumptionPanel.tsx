import { useEffect, useRef, useState, FormEvent } from "react";
type Row = Record<string, any>;
type Props = {
    vehicle: Row;
    streams: Row[];
    api: (path: string, method?: string, data?: Row | FormData) => Promise<any>;
    edit: (id: string) => void;
};
const decimal = (v: string | null | undefined) => {
    if (v == null) return "—";
    const [whole, fraction = ""] = v.split(".");
    const rest = fraction.replace(/0+$/, "");
    return (
        whole.replace(/\B(?=(\d{3})+(?!\d))/g, "\u202f") +
        (rest ? "," + rest : "")
    );
};
const date = (v: string | null) =>
    v ? v.split("-").reverse().join(". ") : "—";
const reasons: Row = {
    full_anchor:
        "Výchozí plná nádrž. Její množství se do následujícího intervalu nezapočítává.",
    delivery_anchor:
        "Výchozí odečet pro přehled dodávek. Toto množství je mimo první měřený interval.",
    complete_interval: "Úplný interval plná–plná.",
    delivery_interval:
        "Dodávka mezi doloženými odečty vzdálenosti. Nejde o skutečnou spotřebu nádrže ani baterie.",
    partial_fueling: "Částečné tankování čeká na uzavírací plnou nádrž.",
    partial_in_complete_interval:
        "Částečné tankování je zahrnuté v následujícím úplném intervalu.",
    partial_outside_period:
        "Částečné tankování patří do intervalu přesahujícího vybrané období.",
    interrupted_interval:
        "Navazující historie byla přerušena. Toto částečné tankování nemá úplný interval.",
    missing_anchor:
        "Chybí předchozí plná nádrž. První následující plné tankování založí výchozí bod.",
    first_fueling:
        "První tankování zahajuje novou historii. U plná–plná samo nepotvrzuje plnou nádrž.",
    invalid_fueling:
        "Neplatné tankování přerušuje výpočetní řetězec; finančně zůstává evidované.",
    missing_odometer:
        "Chybí tachometr. Interval se přes tento záznam nepočítá.",
    odometer_decrease: "Tachometr klesl. Předchozí řetězec nelze použít.",
    zero_distance:
        "Nulová vzdálenost: spotřebu ani dodávku na vzdálenost nelze určit.",
    ambiguous_order:
        "Pořadí tankování v tomto dni není jednoznačné. Nastavte všem záznamům v nádrži rozdílné pořadí 1, 2, …",
    unit_mismatch:
        "Jednotka záznamu neodpovídá nádrži nebo vozidlu. Jednotky se automaticky nepřevádějí.",
    medium_mismatch: "Palivo neodpovídá médiu této nádrže.",
    invalid_quantity: "Množství nelze použít pro výpočet.",
    unknown_fueling_type: "Neznámý typ tankování přerušuje řetězec.",
    unsupported_method: "Tato metoda výpočtu není podporovaná.",
    deleted_event:
        "Vyřazený záznam není v nákladech ani množství dodávek. Jeho místo v historii brání výpočtu přes chybějící tankování.",
    history_gap:
        "Před záznamem je označená chybějící historie; předchozí interval byl přerušen.",
    outside_period:
        "Úplný interval přesahuje vybrané období. Je zobrazen pro vysvětlení, ale do průměru se nezapočítá.",
    explicit_history_gap:
        "Je označená chybějící historie před tímto tankováním.",
    trip_difference:
        "Importovaný Trip se od rozdílu sousedních tachometrů liší o více než 1 jednotku. Výpočet používá tachometry.",
};
export function ConsumptionPanel({ vehicle, streams, api, edit }: Props) {
    const [draft, setDraft] = useState({
        from: "",
        to: "",
        energy_stream_id: "",
    });
    const [filters, setFilters] = useState(draft);
    const [page, setPage] = useState(1);
    const [report, setReport] = useState<Row | null>(null);
    const [error, setError] = useState("");
    const [busy, setBusy] = useState(false);
    const [refresh, setRefresh] = useState(0);
    const version = useRef(0);
    useEffect(() => {
        const n = ++version.current;
        setBusy(true);
        setError("");
        const params = new URLSearchParams({ ...filters, page: String(page) });
        api(`/vehicles/${vehicle.id}/consumption?${params}`)
            .then((d) => {
                if (n === version.current) setReport(d);
            })
            .catch((e) => {
                if (n === version.current) {
                    setReport(null);
                    setError(e.message);
                }
            })
            .finally(() => {
                if (n === version.current) setBusy(false);
            });
        return () => {
            version.current++;
        };
    }, [vehicle.id, filters, page, refresh]);
    function apply(e: FormEvent) {
        e.preventDefault();
        setFilters({ ...draft });
        setPage(1);
    }
    return (
        <section
            className="consumption-panel"
            aria-label="Spotřeba a kvalita historie"
        >
            <h2>Spotřeba a kvalita historie</h2>
            <p>
                Výpočty jsou oddělené podle nádrží a médií. Průměr zahrnuje
                pouze celé intervaly ležící uvnitř vybraného období. Měna ani
                druh paliva historii pro výpočet neomezují.
            </p>
            <form onSubmit={apply} className="filters">
                <fieldset disabled={busy}>
                    <div className="fields">
                        <label>
                            Spotřeba od data
                            <input
                                type="date"
                                value={draft.from}
                                onChange={(e) =>
                                    setDraft({ ...draft, from: e.target.value })
                                }
                            />
                        </label>
                        <label>
                            Spotřeba do data
                            <input
                                type="date"
                                value={draft.to}
                                onChange={(e) =>
                                    setDraft({ ...draft, to: e.target.value })
                                }
                            />
                        </label>
                        <label>
                            Nádrž / médium pro výpočet
                            <select
                                value={draft.energy_stream_id}
                                onChange={(e) =>
                                    setDraft({
                                        ...draft,
                                        energy_stream_id: e.target.value,
                                    })
                                }
                            >
                                <option value="">
                                    Všechny nádrže odděleně
                                </option>
                                {streams.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name} ({s.quantity_unit})
                                        {!s.is_active ? " – neaktivní" : ""}
                                    </option>
                                ))}
                            </select>
                        </label>
                    </div>
                    <div className="actions">
                        <button>Zobrazit spotřebu</button>
                        <button
                            type="button"
                            className="secondary"
                            onClick={() => {
                                const empty = {
                                    from: "",
                                    to: "",
                                    energy_stream_id: "",
                                };
                                setDraft(empty);
                                setFilters(empty);
                                setPage(1);
                            }}
                        >
                            Celá historie
                        </button>
                        <button
                            type="button"
                            className="secondary"
                            onClick={() => setRefresh(refresh + 1)}
                        >
                            Přepočítat
                        </button>
                    </div>
                </fieldset>
            </form>
            {error && (
                <p role="alert" className="error">
                    {error}
                </p>
            )}
            {busy && <p role="status">Počítám spotřebu…</p>}
            {!busy && report && (
                <>
                    {!report.streams.length && (
                        <p>Vozidlo zatím nemá žádnou nádrž nebo médium.</p>
                    )}
                    <div className="consumption-summaries">
                        {report.streams.map((s: Row) => (
                            <article className="consumption-summary" key={s.id}>
                                <small>
                                    {s.method === "delivery_only"
                                        ? "DODÁVKY NA VZDÁLENOST"
                                        : "VÁŽENÁ SPOTŘEBA PLNÁ–PLNÁ"}
                                </small>
                                <h3>
                                    {s.name}
                                    {!s.is_active ? " · neaktivní" : ""}
                                </h3>
                                <strong className="consumption-value">
                                    {decimal(
                                        s.method === "delivery_only"
                                            ? s.delivery_per_distance
                                            : s.average_consumption,
                                    )}{" "}
                                    <span>
                                        {s.quantity_unit}/100 {s.distance_unit}
                                    </span>
                                </strong>
                                {s.method === "delivery_only" && (
                                    <p className="notice">
                                        Jde o dodané množství na doloženou
                                        vzdálenost, nikoli o skutečnou spotřebu.
                                        U elektřiny nejsou známé stavy baterie
                                        ani ztráty.
                                    </p>
                                )}
                                {!s.interval_count ? (
                                    <p>
                                        Pro vybrané období zatím není úplný
                                        použitelný interval. Důvody jsou uvedené
                                        u jednotlivých záznamů níže.
                                    </p>
                                ) : (
                                    <>
                                        <p>
                                            {s.interval_count} úplných intervalů
                                            · {decimal(s.accounted_quantity)}{" "}
                                            {s.quantity_unit} /{" "}
                                            {decimal(s.accounted_distance)}{" "}
                                            {s.distance_unit} × 100
                                        </p>
                                        <p>
                                            Hranice započtených intervalů:{" "}
                                            {date(s.coverage_from)} až{" "}
                                            {date(s.coverage_to)}. Vzdálenost je
                                            jejich součet; mezery se
                                            nepřičítají.
                                        </p>
                                    </>
                                )}
                                <p>
                                    Dodáno v období:{" "}
                                    {decimal(s.delivered_quantity)}{" "}
                                    {s.quantity_unit}. Tankování mimo započtené
                                    intervaly: {s.uncovered_event_count}.
                                </p>
                                {s.open_interval && (
                                    <p className="notice">
                                        Otevřený interval od{" "}
                                        {date(s.open_interval.start.date)}:
                                        zatím{" "}
                                        {decimal(s.open_interval.quantity)}{" "}
                                        {s.quantity_unit}. Čeká na plné
                                        tankování.
                                    </p>
                                )}
                            </article>
                        ))}
                    </div>
                    <div className="consumption-observations">
                        <h3>Podklady a vysvětlení výpočtu</h3>
                        <p>
                            Údaje Consumption a BC pocházejí ze zdroje.
                            Vypočtené hodnoty je nepřepisují. Poslední záznamy
                            jsou nahoře.
                        </p>
                        {!report.observations.data.length && (
                            <p>
                                Ve vybraném období nejsou žádná tankování ani
                                záznamy přerušující historii.
                            </p>
                        )}
                        {report.observations.data.map((r: Row) => {
                            const s = report.streams.find(
                                (s: Row) => s.id === r.energy_stream_id,
                            );
                            const value =
                                s.method === "delivery_only"
                                    ? r.delivery_per_distance
                                    : r.computed_consumption;
                            return (
                                <article
                                    key={r.event_id}
                                    className={`consumption-row ${r.status}`}
                                >
                                    <div className="heading">
                                        <strong>
                                            {date(r.occurred_on)} · #
                                            {r.event_id} · {s.name}
                                        </strong>
                                        {!vehicle.archived_at && !r.deleted && (
                                            <button
                                                disabled={busy}
                                                className="secondary"
                                                onClick={() => edit(r.event_id)}
                                            >
                                                Upravit tankování #{r.event_id}
                                            </button>
                                        )}
                                    </div>
                                    <p>
                                        {decimal(r.quantity)} {r.quantity_unit}{" "}
                                        · tachometr {decimal(r.odometer)}{" "}
                                        {r.distance_unit}
                                        {r.day_sequence
                                            ? ` · pořadí ${r.day_sequence}`
                                            : ""}
                                    </p>
                                    <p
                                        className={
                                            r.status === "unavailable" ||
                                            r.status === "excluded"
                                                ? "notice"
                                                : ""
                                        }
                                    >
                                        {reasons[r.reason] ?? r.reason}
                                    </p>
                                    {r.starts_new_chain &&
                                        r.status !== "anchor" && (
                                            <p>
                                                Od tohoto doloženého odečtu
                                                začíná nový řetězec.
                                            </p>
                                        )}
                                    {r.interval && (
                                        <div className="interval-detail">
                                            <p>
                                                Od #{r.interval.start.event_id}{" "}
                                                ({date(r.interval.start.date)},{" "}
                                                {decimal(
                                                    r.interval.start.odometer,
                                                )}{" "}
                                                {r.distance_unit}) do #
                                                {r.interval.end.event_id} (
                                                {date(r.interval.end.date)},{" "}
                                                {decimal(
                                                    r.interval.end.odometer,
                                                )}{" "}
                                                {r.distance_unit}).
                                            </p>
                                            <p>
                                                <strong>
                                                    {s.method ===
                                                    "delivery_only"
                                                        ? "Dodáno na vzdálenost"
                                                        : "Vypočtená spotřeba"}
                                                    : {decimal(value)}{" "}
                                                    {s.quantity_unit}/100{" "}
                                                    {r.distance_unit}
                                                </strong>
                                            </p>
                                            <p>
                                                {decimal(r.interval.quantity)}{" "}
                                                {s.quantity_unit} ÷{" "}
                                                {decimal(r.interval.distance)}{" "}
                                                {r.distance_unit} × 100.
                                                Zahrnuté nákupy:{" "}
                                                {r.interval.event_ids
                                                    .map(
                                                        (id: string) =>
                                                            `#${id}`,
                                                    )
                                                    .join(", ")}
                                                .
                                            </p>
                                            {!r.interval
                                                .included_in_average && (
                                                <small>
                                                    Nezahrnuto do průměru
                                                    zvoleného období.
                                                </small>
                                            )}
                                        </div>
                                    )}
                                    {r.closing_event_id && (
                                        <p>
                                            Uzavírá záznam #{r.closing_event_id}
                                            .
                                        </p>
                                    )}
                                    {r.break_event_id && (
                                        <p>
                                            Přerušení u záznamu #
                                            {r.break_event_id}.
                                        </p>
                                    )}
                                    <small>
                                        Importovaná spotřeba (Consumption):{" "}
                                        {decimal(r.reported_consumption)} ·
                                        palubní (BC):{" "}
                                        {decimal(r.bc_consumption)} · jednotka
                                        zdroje {r.quantity_unit}/100{" "}
                                        {r.source_distance_unit}
                                    </small>
                                    {r.warnings.map((w: string) => (
                                        <p className="notice" key={w}>
                                            {reasons[w] ?? w}
                                        </p>
                                    ))}
                                </article>
                            );
                        })}
                        <div className="actions">
                            <button
                                disabled={busy || page <= 1}
                                className="secondary"
                                onClick={() => setPage(page - 1)}
                            >
                                Předchozí podklady
                            </button>
                            <span>
                                {page} / {report.observations.last_page}
                            </span>
                            <button
                                disabled={
                                    busy ||
                                    page >= report.observations.last_page
                                }
                                className="secondary"
                                onClick={() => setPage(page + 1)}
                            >
                                Další podklady
                            </button>
                        </div>
                    </div>
                </>
            )}
        </section>
    );
}
