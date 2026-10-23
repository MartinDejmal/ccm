import { useEffect, useRef, useState, FormEvent } from "react";
type Row = Record<string, any>;
const colors = [
    "#2166ac",
    "#ef7130",
    "#19a782",
    "#e5a000",
    "#df72a5",
    "#258132",
    "#6044ad",
    "#548996",
    "#9e623c",
    "#7d8190",
];
const decimal = (v: string | number) => {
    const [w, f = ""] = String(v).split(".");
    return (
        w.replace(/\B(?=(\d{3})+(?!\d))/g, "\u202f") +
        (f.replace(/0+$/, "") ? "," + f.replace(/0+$/, "") : "")
    );
};
const monthIndex = (s: string) =>
    Number(s.slice(0, 4)) * 12 + Number(s.slice(5, 7)) - 1;
function Trend({ rows, unit }: { rows: Row[]; unit: string }) {
    if (!rows.length)
        return (
            <p className="chart-empty">
                Pro zvolené období chybí úplné intervaly.
            </p>
        );
    const first = monthIndex(rows[0].month),
        last = monthIndex(rows[rows.length - 1].month),
        max = Math.max(...rows.map((r) => Number(r.value)), 1) * 1.15;
    const x = (r: Row) =>
        first === last
            ? 320
            : 50 + ((monthIndex(r.month) - first) / (last - first)) * 550;
    const y = (r: Row) => 220 - (Number(r.value) / max) * 185;
    const path = rows
        .map(
            (r, i) =>
                `${i === 0 || monthIndex(r.month) - monthIndex(rows[i - 1].month) > 1 ? "M" : "L"}${x(r)},${y(r)}`,
        )
        .join(" ");
    return (
        <>
            <svg
                className="analytics-svg"
                viewBox="0 0 640 265"
                role="img"
                aria-label={`Měsíční vývoj v ${unit}. Hodnoty jsou také v tabulce pod grafem.`}
            >
                <title>
                    Měsíční vážená spotřeba; chybějící měsíce se nespojují
                </title>
                {[0, 1, 2, 3, 4].map((n) => (
                    <g key={n}>
                        <line
                            x1="50"
                            x2="600"
                            y1={220 - n * 46.25}
                            y2={220 - n * 46.25}
                            stroke="#dce6df"
                        />
                        <text x="42" y={224 - n * 46.25} textAnchor="end">
                            {((max * n) / 4).toLocaleString("cs-CZ", {
                                maximumFractionDigits: 1,
                            })}
                        </text>
                    </g>
                ))}
                <path d={path} fill="none" stroke="#2166ac" strokeWidth="2.5" />
                {rows.map((r, i) => (
                    <g key={r.month}>
                        <circle
                            tabIndex={0}
                            cx={x(r)}
                            cy={y(r)}
                            r="4"
                            fill="#2166ac"
                        >
                            <title>
                                {r.month}: {decimal(r.value)} {unit};{" "}
                                {r.interval_count} intervalů
                            </title>
                        </circle>
                        {(i === 0 ||
                            i === rows.length - 1 ||
                            i === Math.floor(rows.length / 2)) && (
                            <text x={x(r)} y="247" textAnchor="middle">
                                {r.month}
                            </text>
                        )}
                    </g>
                ))}
            </svg>
            <details>
                <summary>Data měsíčního grafu</summary>
                <div className="analytics-table">
                    <table>
                        <thead>
                            <tr>
                                <th>Měsíc uzavření</th>
                                <th>{unit}</th>
                                <th>Vzdálenost</th>
                                <th>Intervaly</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((r) => (
                                <tr key={r.month}>
                                    <td>{r.month}</td>
                                    <td>{decimal(r.value)}</td>
                                    <td>{decimal(r.distance)}</td>
                                    <td>{r.interval_count}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </details>
        </>
    );
}
function Histogram({ rows, unit }: { rows: Row[]; unit: string }) {
    if (!rows.length)
        return (
            <p className="chart-empty">
                Pro histogram chybí úplné intervaly s množstvím v litrech.
            </p>
        );
    const min = Number(rows[0].lower),
        max = Number(rows[rows.length - 1].upper),
        peak = Math.max(...rows.map((r) => r.count)),
        tickStep = Math.max(1, Math.ceil(peak / 4)),
        yMax = tickStep * 4;
    const width = Math.min(48, ((550 * 0.5) / (max - min)) * 0.8);
    return (
        <>
            <svg
                className="analytics-svg"
                viewBox="0 0 640 265"
                role="img"
                aria-label={`Počet úplných intervalů podle spotřeby po 0,5 ${unit}. Přesné počty jsou v tabulce.`}
            >
                <title>
                    Histogram spotřeby; dolní mez patří do intervalu, horní
                    nikoli
                </title>
                {[0, 1, 2, 3, 4].map((n) => (
                    <g key={n}>
                        <line
                            x1="50"
                            x2="600"
                            y1={220 - n * 46.25}
                            y2={220 - n * 46.25}
                            stroke="#dce6df"
                        />
                        <text x="42" y={224 - n * 46.25} textAnchor="end">
                            {(tickStep * n).toLocaleString("cs-CZ")}
                        </text>
                    </g>
                ))}
                {rows.map((r, i) => {
                    const x =
                            50 +
                            ((Number(r.lower) - min + 0.25) / (max - min)) *
                                550,
                        h = (r.count / yMax) * 185;
                    return (
                        <g key={r.lower}>
                            <rect
                                tabIndex={0}
                                x={x - width / 2}
                                y={220 - h}
                                width={width}
                                height={h}
                                rx="2"
                                fill="#19a782"
                            >
                                <title>
                                    {decimal(r.lower)} až méně než{" "}
                                    {decimal(r.upper)} {unit}: {r.count}{" "}
                                    intervalů
                                </title>
                            </rect>
                            {(i === 0 ||
                                i === rows.length - 1 ||
                                i === Math.floor(rows.length / 2)) && (
                                <text x={x} y="247" textAnchor="middle">
                                    {decimal(r.lower)}
                                </text>
                            )}
                        </g>
                    );
                })}
            </svg>
            <p className="help">
                Osa X: dolní mez spotřeby ({unit}). Osa Y: počet úplných
                intervalů. Prázdná pásma mají nulovou četnost.
            </p>
            <details>
                <summary>Data histogramu</summary>
                <div className="analytics-table">
                    <table>
                        <thead>
                            <tr>
                                <th>Pásmo ({unit})</th>
                                <th>Počet intervalů</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((r) => (
                                <tr key={r.lower}>
                                    <td>
                                        {decimal(r.lower)} až &lt;{" "}
                                        {decimal(r.upper)}
                                    </td>
                                    <td>{r.count}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </details>
        </>
    );
}
export function AnalyticsPanel({
    vehicle,
    streams,
    catalogs,
    api,
}: {
    vehicle: Row;
    streams: Row[];
    catalogs: Row;
    api: (path: string) => Promise<any>;
}) {
    const [draft, setDraft] = useState({
        from: "",
        to: "",
        currency: vehicle.currency_code,
        energy_stream_id: "",
    });
    const [filters, setFilters] = useState(draft),
        [report, setReport] = useState<Row | null>(null),
        [error, setError] = useState(""),
        [busy, setBusy] = useState(false);
    const version = useRef(0);
    useEffect(() => {
        const n = ++version.current;
        setBusy(true);
        setReport(null);
        setError("");
        api(`/vehicles/${vehicle.id}/analytics?${new URLSearchParams(filters)}`)
            .then((d) => {
                if (n === version.current) setReport(d);
            })
            .catch((e) => {
                if (n === version.current) setError(e.message);
            })
            .finally(() => {
                if (n === version.current) setBusy(false);
            });
        return () => {
            version.current++;
        };
    }, [vehicle.id, filters]);
    function submit(e: FormEvent) {
        e.preventDefault();
        setFilters({ ...draft });
    }
    const categories: Row[] = report?.costs.categories ?? [];
    let progress = 0;
    const stops = categories
        .filter((r) => Number(r.positive) > 0)
        .map((r) => {
            const start = progress;
            progress += Number(r.percent);
            return `${colors[categories.indexOf(r) % colors.length]} ${start}% ${progress}%`;
        });
    return (
        <section className="analytics-panel">
            <h2>Analytika</h2>
            <form className="filters" onSubmit={submit}>
                <div className="fields">
                    <label>
                        Analytika od data
                        <input
                            type="date"
                            value={draft.from}
                            onChange={(e) =>
                                setDraft({ ...draft, from: e.target.value })
                            }
                        />
                    </label>
                    <label>
                        Analytika do data
                        <input
                            type="date"
                            value={draft.to}
                            onChange={(e) =>
                                setDraft({ ...draft, to: e.target.value })
                            }
                        />
                    </label>
                    <label>
                        Měna nákladů
                        <select
                            value={draft.currency}
                            onChange={(e) =>
                                setDraft({ ...draft, currency: e.target.value })
                            }
                        >
                            {(catalogs.currencies ?? []).map((c: Row) => (
                                <option key={c.code} value={c.code}>
                                    {c.code}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label>
                        Médium spotřeby
                        <select
                            value={draft.energy_stream_id}
                            onChange={(e) =>
                                setDraft({
                                    ...draft,
                                    energy_stream_id: e.target.value,
                                })
                            }
                        >
                            <option value="">Všechna média zvlášť</option>
                            {streams.map((s) => (
                                <option key={s.id} value={s.id}>
                                    {s.name}
                                </option>
                            ))}
                        </select>
                    </label>
                </div>
                <button disabled={busy}>Zobrazit analytiku</button>
            </form>
            {error && (
                <p role="alert" className="error">
                    {error}
                </p>
            )}
            {busy && <p role="status">Načítání analytiky…</p>}
            {report && (
                <>
                    <article className="analytics-card">
                        <h3>Rozložení nákladů · {report.currency_code}</h3>
                        <p className="help">
                            Výseče ukazují kladné výdaje včetně pořizovací ceny.
                            Vratky jsou samostatně; měny se nesčítají. Filtr
                            média mění pouze grafy spotřeby.
                        </p>
                        <div className="cost-distribution">
                            {Number(report.costs.gross) > 0 ? (
                                <div
                                    className="cost-donut"
                                    role="img"
                                    aria-label={`Rozložení kladných nákladů: ${decimal(report.costs.gross)} ${report.currency_code}. Podrobnosti v legendě.`}
                                    style={{
                                        background: `conic-gradient(${stops.join(",")})`,
                                    }}
                                >
                                    <div>
                                        <small>Celkem po vratkách</small>
                                        <strong>
                                            {decimal(report.costs.net)}
                                        </strong>
                                        <small>{report.currency_code}</small>
                                    </div>
                                </div>
                            ) : (
                                <p className="chart-empty">
                                    Žádné kladné náklady v této měně a období.
                                </p>
                            )}
                            <ul className="cost-legend">
                                {categories.map((c, i) => (
                                    <li key={c.category}>
                                        <span
                                            className="legend-swatch"
                                            style={{
                                                background:
                                                    colors[i % colors.length],
                                            }}
                                        />
                                        <span>{c.label}</span>
                                        <span className="legend-percent">
                                            {decimal(c.percent)} %
                                        </span>
                                        <strong>
                                            {decimal(c.positive)}{" "}
                                            {report.currency_code}
                                        </strong>
                                    </li>
                                ))}
                            </ul>
                        </div>
                        <p className="help">
                            Kladné výdaje: {decimal(report.costs.gross)} ·
                            Vratky: {decimal(report.costs.refunds)} · Po
                            vratkách: {decimal(report.costs.net)}{" "}
                            {report.currency_code}
                        </p>
                        <details>
                            <summary>
                                Částky po kategoriích včetně vratek
                            </summary>
                            <div className="analytics-table">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Kategorie</th>
                                            <th>Kladné výdaje</th>
                                            <th>Vratky</th>
                                            <th>Po vratkách</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {categories.map((c) => (
                                            <tr key={c.category}>
                                                <td>{c.label}</td>
                                                <td>{decimal(c.positive)}</td>
                                                <td>{decimal(c.refunds)}</td>
                                                <td>{decimal(c.net)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </details>
                    </article>
                    <p className="help">
                        Spotřeba používá pouze úplné intervaly celé uvnitř
                        zvoleného období. Měsíc určuje uzavírací tankování; jde
                        o souhrn intervalů, nikoli přesné rozdělení jízdy mezi
                        kalendářní měsíce. Průměr = 100 × součet množství /
                        součet vzdáleností. Chybějící měsíce se nespojují.
                    </p>
                    {report.streams.map((s: Row) => {
                        const unit = `${s.quantity_unit}/100 ${s.distance_unit}`;
                        return (
                            <div key={s.id}>
                                <h3>
                                    {s.name} · {s.sample_count} úplných
                                    intervalů
                                </h3>
                                <div className="analytics-grid">
                                    <article className="analytics-card">
                                        <h3>
                                            {s.method === "delivery_only"
                                                ? "Měsíční dodávky na vzdálenost"
                                                : "Spotřeba po měsících"}{" "}
                                            · {unit}
                                        </h3>
                                        {s.method === "delivery_only" && (
                                            <p className="help">
                                                Dodávky elektřiny nebo AdBlue
                                                nejsou měřením skutečné
                                                spotřeby.
                                            </p>
                                        )}
                                        <Trend rows={s.monthly} unit={unit} />
                                    </article>
                                    <article className="analytics-card">
                                        <h3>
                                            Histogram spotřeby po 0,5 {unit}
                                        </h3>
                                        {s.method === "full_to_full" &&
                                        s.quantity_unit === "l" ? (
                                            <Histogram
                                                rows={s.histogram}
                                                unit={unit}
                                            />
                                        ) : (
                                            <p className="chart-empty">
                                                Histogram po půllitrech je
                                                dostupný pro spotřebu plná–plná
                                                v litrech.
                                            </p>
                                        )}
                                    </article>
                                </div>
                            </div>
                        );
                    })}
                    {!report.streams.length && (
                        <p>Vozidlo nemá evidované médium.</p>
                    )}
                </>
            )}
            <details className="analytics-suggestions">
                <summary>Další doporučené přehledy</summary>
                <ul>
                    <li>
                        Výdaje po měsících a kumulativní náklady, zvlášť provoz
                        a pořízení.
                    </li>
                    <li>
                        Vývoj ceny paliva za litr, odděleně podle paliva a měny.
                    </li>
                    <li>
                        Náklady na kilometr pro doložené období a vzdálenost.
                    </li>
                    <li>Roční srovnání spotřeby a sezónní rozdíly.</li>
                    <li>
                        Plánované platby na příštích 12 měsíců, oddělené od
                        skutečných výdajů.
                    </li>
                </ul>
            </details>
        </section>
    );
}
