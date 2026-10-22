import { useEffect, useRef, useState, FormEvent } from "react";
import { createRequestKey } from "../lib/request-key";
type Row = Record<string, any>;
type Props = {
    vehicle: Row;
    streams: Row[];
    api: (path: string, method?: string, data?: Row | FormData) => Promise<any>;
};
export function ImportPanel({ vehicle, streams, api }: Props) {
    const version = useRef(0);
    const [kind, setKind] = useState("fueling"),
        [profile, setProfile] = useState("spritmonitor"),
        [separator, setSeparator] = useState(","),
        [stream, setStream] = useState("");
    const [file, setFile] = useState<File | null>(null),
        [error, setError] = useState(""),
        [busy, setBusy] = useState(false),
        [batches, setBatches] = useState<Row[]>([]),
        [batch, setBatch] = useState<Row | null>(null),
        [rows, setRows] = useState<Row[]>([]),
        [page, setPage] = useState(1),
        [last, setLast] = useState(1),
        [status, setStatus] = useState(""),
        [batchPage, setBatchPage] = useState(1),
        [batchLast, setBatchLast] = useState(1);
    const [planRows, setPlanRows] = useState<string[]>([]);
    useEffect(() => {
        setPlanRows([]);
    }, [batch?.id, batch?.revision]);
    const [acknowledged, setAcknowledged] = useState(false);
    const [reversal, setReversal] = useState<Row | null>(null);
    const [keepChanged, setKeepChanged] = useState(false);
    const [notice, setNotice] = useState("");
    const pendingConfirm = useRef<Row | null>(null);
    const pendingRevert = useRef<Row | null>(null);
    const editable = batch && ["ready", "needs_review"].includes(batch.status);
    useEffect(() => {
        setAcknowledged(false);
        setReversal(null);
        setKeepChanged(false);
        pendingConfirm.current = null;
        pendingRevert.current = null;
    }, [batch?.id, batch?.revision]);
    const base = `/vehicles/${vehicle.id}/imports`;
    const currentStream = streams.find((s) => String(s.id) === stream);
    async function run(action: () => Promise<void>) {
        setBusy(true);
        setError("");
        setNotice("");
        try {
            await action();
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setBusy(false);
        }
    }
    async function list() {
        const d = await api(`${base}?page=${batchPage}`);
        setBatches(d.data);
        setBatchLast(d.last_page);
    }
    useEffect(() => {
        run(list);
    }, [vehicle.id, batchPage]);
    useEffect(() => {
        const n = ++version.current;
        if (!batch) {
            setRows([]);
            return;
        }
        api(
            `${base}/${batch.id}/rows?page=${page}${status ? `&status=${status}` : ""}`,
        )
            .then((d) => {
                if (n !== version.current) return;
                setRows(d.data);
                setLast(d.last_page);
            })
            .catch((e) => {
                if (n === version.current) setError(e.message);
            });
        return () => {
            version.current++;
        };
    }, [batch?.id, page, status, batch?.revision]);
    useEffect(() => {
        if (!stream && streams.length) setStream(String(streams[0].id));
    }, [streams]);
    async function upload(e: FormEvent) {
        e.preventDefault();
        await run(async () => {
            if (!file) throw Error("Vyberte CSV soubor.");
            const data = new FormData();
            data.append("file", file);
            data.append("kind", kind);
            data.append("profile", profile);
            data.append("distance_unit", vehicle.distance_unit);
            data.append("decimal_separator", separator);
            if (kind === "fueling") {
                data.append("energy_stream_id", stream);
                data.append(
                    "quantity_unit",
                    currentStream?.quantity_unit ?? "",
                );
            }
            const result = await api(base, "POST", data);
            setBatch(result);
            setStatus("");
            setPage(1);
            await list();
        });
    }
    async function skip(row: Row) {
        await run(async () => {
            const updated = await api(
                `${base}/${batch!.id}/rows/${row.id}`,
                "PATCH",
                { skip: row.status !== "skipped", revision: batch!.revision },
            );
            setBatch(updated);
            const d = await api(
                `${base}/${batch!.id}/rows?page=${page}${status ? `&status=${status}` : ""}`,
            );
            setRows(d.data);
            setLast(d.last_page);
            if (page > d.last_page) setPage(d.last_page);
            await list();
        });
    }
    async function accept(updated: Row) {
        setBatch(updated);
        await list();
    }
    async function review() {
        await run(async () =>
            accept(
                await api(`${base}/${batch!.id}/review`, "POST", {
                    revision: batch!.revision,
                }),
            ),
        );
    }
    async function decide(row: Row, keep: boolean) {
        await run(async () =>
            accept(
                await api(
                    `${base}/${batch!.id}/rows/${row.id}/duplicate`,
                    "PATCH",
                    {
                        revision: batch!.revision,
                        duplicate_token: row.duplicates.token,
                        keep,
                    },
                ),
            ),
        );
    }
    async function confirm() {
        await run(async () => {
            pendingConfirm.current ??= {
                revision: batch!.revision,
                review_token: batch!.review_token,
                request_key: createRequestKey(),
                acknowledged: true,
                plan_row_ids: planRows,
            };
            await accept(
                await api(
                    `${base}/${batch!.id}/confirm`,
                    "POST",
                    pendingConfirm.current,
                ),
            );
            setNotice(
                "Import byl potvrzen. Záznamy jsou nyní součástí historie a součtů vozidla.",
            );
        });
    }
    async function revert() {
        await run(async () => {
            pendingRevert.current ??= {
                revision: reversal!.revision,
                review_token: reversal!.review_token,
                request_key: createRequestKey(),
                conflict_policy: keepChanged ? "keep_changed" : "abort",
                acknowledged: true,
            };
            await accept(
                await api(
                    `${base}/${batch!.id}/revert`,
                    "POST",
                    pendingRevert.current,
                ),
            );
            setNotice(
                "Dávka byla vrácena. Později změněné záznamy zůstaly zachované podle vaší volby.",
            );
        });
    }
    const labels: Row = {
        valid: "V pořádku",
        warning: "Varování",
        error: "Chyba",
        skipped: "Přeskočeno",
        ready: "Připravený náhled",
        needs_review: "Vyžaduje kontrolu",
        cancelled: "Zrušeno",
        imported: "Importováno",
        reverted: "Vráceno",
    };
    return (
        <section className="history import-panel">
            <span className="badge">CSV / SPRITMONITOR</span>
            <h2>CSV import</h2>
            <p>
                Vyberte soubor pro vozidlo <strong>{vehicle.name}</strong>.
                Nejprve zkontrolujte náhled a duplicity. Záznamy se do historie
                zapíší až po výslovném potvrzení importu.
            </p>
            {error && (
                <p className="error" role="alert">
                    {error}
                </p>
            )}
            {notice && (
                <p role="status" className="success">
                    {notice}
                </p>
            )}
            {!vehicle.archived_at && (
                <form onSubmit={upload}>
                    <div className="fields">
                        <label>
                            Obsah souboru
                            <select
                                value={kind}
                                onChange={(e) => setKind(e.target.value)}
                            >
                                <option value="fueling">
                                    Tankování / nabíjení
                                </option>
                                <option value="expense">Ostatní výdaje</option>
                            </select>
                        </label>
                        <label>
                            Zdroj / profil
                            <select
                                value={profile}
                                onChange={(e) => setProfile(e.target.value)}
                            >
                                <option value="spritmonitor">
                                    SpritMonitor – uvozovky s lomítkem
                                </option>
                                <option value="spritmonitor_rfc">
                                    SpritMonitor – zdvojené uvozovky
                                </option>
                                <option value="ccm">
                                    CCM – zachovat nulové BC údaje
                                </option>
                            </select>
                        </label>
                        <label>
                            Desetinný oddělovač
                            <select
                                value={separator}
                                onChange={(e) => setSeparator(e.target.value)}
                            >
                                <option value=",">Čárka (63,64)</option>
                                <option value=".">Tečka (63.64)</option>
                            </select>
                        </label>
                        <label>
                            Jednotka vzdálenosti
                            <input value={vehicle.distance_unit} readOnly />
                            <small>
                                Potvrďte, že soubor používá stejnou jednotku
                                jako vozidlo.
                            </small>
                        </label>
                        {kind === "fueling" && (
                            <>
                                <label>
                                    Nádrž / médium pro soubor
                                    <select
                                        value={stream}
                                        required
                                        onChange={(e) =>
                                            setStream(e.target.value)
                                        }
                                    >
                                        <option value="">Vyberte…</option>
                                        {streams
                                            .filter((s) => s.is_active)
                                            .map((s) => (
                                                <option key={s.id} value={s.id}>
                                                    {s.name} ({s.quantity_unit})
                                                </option>
                                            ))}
                                    </select>
                                </label>
                                <p>
                                    Množství v souboru:{" "}
                                    <strong>
                                        {currentStream?.quantity_unit ??
                                            "vyberte médium"}
                                    </strong>
                                    . Pro jiné médium použijte samostatný
                                    soubor.
                                </p>
                            </>
                        )}
                        <label>
                            CSV soubor (UTF-8, nejvýše 2 MiB / 5 000 záznamů)
                            <input
                                type="file"
                                accept=".csv,text/csv"
                                required
                                onChange={(e) =>
                                    setFile(e.target.files?.[0] ?? null)
                                }
                            />
                        </label>
                        <label className="check">
                            <input type="checkbox" required />
                            Potvrzuji jednotky a zvolené vozidlo.
                        </label>
                    </div>
                    <button disabled={busy}>Zkontrolovat soubor</button>
                </form>
            )}
            <h3>Uložené dávky</h3>
            {!batches.length ? (
                <p>Zatím žádný náhled.</p>
            ) : (
                <div className="batch-list">
                    {batches.map((b) => (
                        <button
                            disabled={busy}
                            key={b.id}
                            className="secondary"
                            onClick={() =>
                                run(async () => {
                                    setBatch(await api(`${base}/${b.id}`));
                                    setStatus("");
                                    setPage(1);
                                })
                            }
                        >
                            #{b.id} · {b.original_filename} · {labels[b.status]}
                        </button>
                    ))}
                </div>
            )}
            <div className="actions">
                <button
                    className="secondary"
                    disabled={busy || batchPage <= 1}
                    onClick={() => setBatchPage(batchPage - 1)}
                >
                    Náhledy: předchozí stránka
                </button>
                <span>
                    {batchPage} / {batchLast}
                </span>
                <button
                    className="secondary"
                    disabled={busy || batchPage >= batchLast}
                    onClick={() => setBatchPage(batchPage + 1)}
                >
                    Další stránka náhledů
                </button>
            </div>
            {batch && (
                <div className="preview">
                    <h3>
                        #{batch.id} · {batch.original_filename}
                    </h3>
                    <p>
                        <strong>{labels[batch.status]}</strong> ·{" "}
                        {batch.summary.total_rows} záznamů ·{" "}
                        {batch.summary.error_rows} chybových ·{" "}
                        {batch.summary.warning_rows} s varováním ·{" "}
                        {batch.summary.skipped_rows} přeskočeno
                    </p>
                    {(batch.summary.warnings ?? []).map(
                        (w: string, i: number) => (
                            <p className="notice" key={i}>
                                {w}
                            </p>
                        ),
                    )}
                    <p>
                        {editable
                            ? "Součty záznamů bez chyb, které nejsou přeskočené:"
                            : "Původní součty zahrnutých řádků dávky:"}{" "}
                        {Object.entries(batch.summary.amounts_by_currency)
                            .map(([c, v]) => `${v} ${c}`)
                            .join(" / ") || "žádné"}
                        {batch.status === "imported"
                            ? ". Aktuální hodnoty po případných úpravách najdete v historii vozidla."
                            : batch.status === "reverted"
                              ? ". Jde o původní součty dávky před vrácením."
                              : ". Tyto částky zatím nejsou součástí nákladů vozidla."}
                    </p>
                    <div className="actions">
                        <label>
                            Zobrazit záznamy
                            <select
                                value={status}
                                onChange={(e) => {
                                    setStatus(e.target.value);
                                    setPage(1);
                                }}
                            >
                                <option value="">Všechny</option>
                                {["valid", "warning", "error", "skipped"].map(
                                    (s) => (
                                        <option key={s} value={s}>
                                            {labels[s]}
                                        </option>
                                    ),
                                )}
                            </select>
                        </label>
                        <button
                            className="secondary"
                            disabled={busy || !editable}
                            onClick={() =>
                                run(async () => {
                                    await api(`${base}/${batch.id}`, "DELETE", {
                                        revision: batch.revision,
                                    });
                                    setBatch(await api(`${base}/${batch.id}`));
                                    await list();
                                })
                            }
                        >
                            Zrušit náhled
                        </button>
                    </div>
                    <div className="import-actions">
                        <button
                            className="secondary"
                            disabled={busy}
                            onClick={() =>
                                run(async () => {
                                    await accept(
                                        await api(`${base}/${batch.id}`),
                                    );
                                    setReversal(null);
                                    setAcknowledged(false);
                                })
                            }
                        >
                            Načíst aktuální dávku
                        </button>
                        {editable && !vehicle.archived_at && (
                            <>
                                <button disabled={busy} onClick={review}>
                                    Ověřit duplicity a připravit potvrzení
                                </button>
                                {!batch.review_token && (
                                    <p className="notice">
                                        Před potvrzením znovu ověřte shody s
                                        historií. Přeskočení řádku vyžaduje
                                        novou kontrolu.
                                    </p>
                                )}
                                {batch.review_token && (
                                    <p>
                                        Řádky s možnou duplicitou:{" "}
                                        {batch.summary.duplicate_rows ?? 0}.
                                        Nevyřešené:{" "}
                                        {batch.summary
                                            .unresolved_duplicate_rows ?? 0}
                                        .
                                    </p>
                                )}
                                <label className="check">
                                    <input
                                        type="checkbox"
                                        checked={acknowledged}
                                        disabled={busy}
                                        onChange={(e) =>
                                            setAcknowledged(e.target.checked)
                                        }
                                    />
                                    Zkontroloval(a) jsem záznamy, varování i
                                    duplicity a chci zahrnuté záznamy zapsat do
                                    historie vozidla.
                                </label>
                                <button
                                    disabled={
                                        busy ||
                                        !acknowledged ||
                                        !batch.review_token ||
                                        !!batch.summary.error_rows ||
                                        !!batch.summary
                                            .unresolved_duplicate_rows ||
                                        !(
                                            batch.summary.valid_rows +
                                            batch.summary.warning_rows
                                        )
                                    }
                                    onClick={confirm}
                                >
                                    Potvrdit import do historie
                                </button>
                            </>
                        )}
                        {batch.result && (
                            <div className="notice">
                                <p>
                                    Importováno: {batch.result.imported_count}{" "}
                                    záznamů · přeskočeno:{" "}
                                    {batch.result.skipped_count}.
                                </p>
                                {batch.result.reversal && (
                                    <p>
                                        Vrácením vyřazeno:{" "}
                                        {batch.result.reversal.removed_count}.
                                        Ponechané změněné záznamy:{" "}
                                        {batch.result.reversal.retained_event_ids.join(
                                            ", ",
                                        ) || "žádné"}
                                        .
                                    </p>
                                )}
                            </div>
                        )}
                        {batch.status === "imported" &&
                            !vehicle.archived_at && (
                                <button
                                    disabled={busy}
                                    className="secondary"
                                    onClick={() =>
                                        run(async () => {
                                            setReversal(
                                                await api(
                                                    `${base}/${batch.id}/reversal`,
                                                ),
                                            );
                                            setKeepChanged(false);
                                            setAcknowledged(false);
                                            pendingRevert.current = null;
                                        })
                                    }
                                >
                                    Zkontrolovat vrácení dávky
                                </button>
                            )}
                        {reversal && batch.status === "imported" && (
                            <div className="notice reversal">
                                <h4>Vrácení importu</h4>
                                <p>
                                    Vyřadí se {reversal.removable_count}{" "}
                                    nezměněných záznamů této dávky. Původní
                                    historie vozidla zůstane zachovaná.
                                </p>
                                {!!reversal.conflicts.length && (
                                    <>
                                        <p>
                                            Později změněné nebo již vyřazené
                                            záznamy: {reversal.conflicts.length}
                                            .
                                        </p>
                                        <ul>
                                            {reversal.conflicts
                                                .slice(0, 20)
                                                .map((e: Row) => (
                                                    <li key={e.id}>
                                                        #{e.id} ·{" "}
                                                        {e.occurred_on} ·{" "}
                                                        {e.amount}{" "}
                                                        {e.currency_code} ·
                                                        revize {e.revision}
                                                        {e.deleted
                                                            ? " · již vyřazeno"
                                                            : ""}
                                                    </li>
                                                ))}
                                        </ul>
                                        <label className="check">
                                            <input
                                                type="checkbox"
                                                checked={keepChanged}
                                                disabled={busy}
                                                onChange={(e) => {
                                                    setKeepChanged(
                                                        e.target.checked,
                                                    );
                                                    pendingRevert.current =
                                                        null;
                                                }}
                                            />
                                            Ponechat změněné záznamy a vrátit
                                            pouze nezměněnou část dávky.
                                        </label>
                                    </>
                                )}
                                <label className="check">
                                    <input
                                        type="checkbox"
                                        checked={acknowledged}
                                        disabled={busy}
                                        onChange={(e) =>
                                            setAcknowledged(e.target.checked)
                                        }
                                    />
                                    Potvrzuji vrácení této dávky z historie.
                                </label>
                                <button
                                    disabled={
                                        busy ||
                                        !acknowledged ||
                                        (!!reversal.conflicts.length &&
                                            !keepChanged)
                                    }
                                    onClick={revert}
                                >
                                    Vrátit dávku z historie
                                </button>
                            </div>
                        )}
                    </div>
                    {reversal && reversal.ending_plans > 0 && (
                        <p className="notice">
                            Vrácení také ukončí {reversal.ending_plans}{" "}
                            aktivních plánů s uvedením důvodu. Skutečné platby
                            mimo tuto dávku zůstanou v historii.
                        </p>
                    )}
                    {rows.map((row) => (
                        <article
                            className={`preview-row ${row.status}`}
                            key={row.id}
                        >
                            <div className="heading">
                                <strong>
                                    Záznam {row.record_number} · řádek{" "}
                                    {row.physical_line_start} ·{" "}
                                    {labels[row.status]}
                                </strong>
                                <button
                                    disabled={busy || !editable}
                                    className="secondary"
                                    onClick={() => skip(row)}
                                >
                                    {row.status === "skipped"
                                        ? "Zahrnout zpět"
                                        : "Přeskočit"}
                                </button>
                            </div>
                            <p>
                                {row.normalized.event.occurred_on ??
                                    "Neplatné datum"}{" "}
                                ·{" "}
                                {row.normalized.event.amount ??
                                    "Neplatná částka"}{" "}
                                {row.normalized.event.currency_code ?? ""}
                            </p>
                            {editable &&
                                row.normalized.event.kind === "expense" &&
                                Number(
                                    row.normalized.detail?.repetition_months,
                                ) > 0 &&
                                ["valid", "warning"].includes(row.status) && (
                                    <label className="check">
                                        <input
                                            type="checkbox"
                                            disabled={busy}
                                            checked={planRows.includes(
                                                String(row.id),
                                            )}
                                            onChange={(e) => {
                                                setPlanRows(
                                                    e.target.checked
                                                        ? [
                                                              ...planRows,
                                                              String(row.id),
                                                          ]
                                                        : planRows.filter(
                                                              (id) =>
                                                                  id !==
                                                                  String(
                                                                      row.id,
                                                                  ),
                                                          ),
                                                );
                                                pendingConfirm.current = null;
                                                setAcknowledged(false);
                                            }}
                                        />
                                        Vytvořit plán z tohoto výdaje.
                                        Historický termín může být po
                                        splatnosti; další platby se samy
                                        nevytvoří.
                                    </label>
                                )}
                            {row.event_id && (
                                <p>
                                    <strong>
                                        Záznam v historii #{row.event_id}
                                        {row.event_deleted_at
                                            ? " · vyřazený"
                                            : ""}
                                    </strong>
                                </p>
                            )}
                            {row.duplicates?.required && (
                                <div className="notice">
                                    <strong>
                                        Možná duplicita
                                        {row.duplicates.approved
                                            ? " – vědomě zachovat"
                                            : " – vyžaduje rozhodnutí"}
                                    </strong>
                                    <p>
                                        Shody v historii:{" "}
                                        {row.duplicates.history_count}.{" "}
                                        {row.duplicates.history
                                            .map(
                                                (e: Row) =>
                                                    `#${e.id} (revize ${e.revision})`,
                                            )
                                            .join(", ")}
                                    </p>
                                    <p>
                                        Podobné předchozí zahrnuté řádky
                                        souboru: {row.duplicates.file_count}.{" "}
                                        {row.duplicates.file
                                            .map(
                                                (r: Row) =>
                                                    `záznam ${r.record_number}`,
                                            )
                                            .join(", ")}
                                    </p>
                                    {editable && batch.review_token && (
                                        <button
                                            className="secondary"
                                            disabled={busy}
                                            onClick={() =>
                                                decide(
                                                    row,
                                                    !row.duplicates.approved,
                                                )
                                            }
                                        >
                                            {row.duplicates.approved
                                                ? "Zrušit souhlas s duplicitou"
                                                : "Zachovat jako další skutečný záznam"}
                                        </button>
                                    )}
                                </div>
                            )}
                            {row.issues.map((issue: Row, i: number) => (
                                <p
                                    key={i}
                                    className={
                                        issue.severity === "error"
                                            ? "error"
                                            : "notice"
                                    }
                                >
                                    <strong>{issue.field}:</strong>{" "}
                                    {issue.message}
                                </p>
                            ))}
                            <details>
                                <summary>Původní záznam a interpretace</summary>
                                <pre>{row.raw_record}</pre>
                                <pre>
                                    {JSON.stringify(row.normalized, null, 2)}
                                </pre>
                            </details>
                        </article>
                    ))}
                    {!rows.length && (
                        <p>Tomuto filtru neodpovídá žádný záznam.</p>
                    )}
                    <div className="actions">
                        <button
                            className="secondary"
                            disabled={page <= 1}
                            onClick={() => setPage(page - 1)}
                        >
                            Předchozí záznamy
                        </button>
                        <span>
                            {page} / {last}
                        </span>
                        <button
                            className="secondary"
                            disabled={page >= last}
                            onClick={() => setPage(page + 1)}
                        >
                            Další záznamy
                        </button>
                    </div>
                </div>
            )}
        </section>
    );
}
