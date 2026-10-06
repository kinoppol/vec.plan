// ledger.jsx — สมุดบัญชี (design 5.15): filterable, reversal instead of delete (BR-21)

function LedgerPage() {
  const { meta, funds } = useApp();
  const { params } = parseHash();
  const [q, setQ] = useState(params.q || '');
  const [qLive, setQLive] = useState(params.q || '');
  const [fund, setFund] = useState(params.fund ? +params.fund : '');
  const [type, setType] = useState(params.type || '');
  const [page, setPage] = useState(1);
  const [open, setOpen] = useState(null);
  useEffect(() => { const t = setTimeout(() => { setQ(qLive); setPage(1); }, 300); return () => clearTimeout(t); }, [qLive]);
  const query = { q, fund, type, project_id: params.project_id, page, per: 50 };
  const { data, error, loading, reload } = useApi('ledger/index', query);
  if (!meta.permissions.view_ledger) return <Forbidden />;

  const cards = funds.roots.flatMap(r => r.fund_type ? [r] : r.children.filter(c => c.fund_type || c.is_leaf));
  const jump = no => { setQLive(no); setQ(no); setFund(''); setType(''); setPage(1); };

  return (
    <div className="stack">
      <PageHead crumb="กองเงิน" title="สมุดบัญชี">
        <button className="btn" onClick={() => download('ledger/export', { q, fund, type, project_id: params.project_id })}><Icon name="download" size={16} />ส่งออก Excel</button>
        {meta.permissions.post_receipt && <button className="btn primary" onClick={() => navigate('receive')}>+ บันทึกรับเงิน</button>}
      </PageHead>
      <div className="card">
        <div className="card-h">
          <div style={{ display: 'flex', flexDirection: 'column' }}>
            <h3>รายการเคลื่อนไหว</h3>
            <span className="xs muted">ไม่มีการลบรายการ ใช้การกลับรายการพร้อมเหตุผลแทน (BR-21)</span>
          </div>
          <div className="row wrap" style={{ gap: 6 }}>
            <button className={'chip' + (fund === '' ? ' on' : '')} onClick={() => { setFund(''); setPage(1); }}><span className="sw" style={{ width: 8, height: 8, borderRadius: 2, background: 'var(--navy)' }} />ทุกกอง</button>
            {cards.map(c => (
              <button key={c.id} className={'chip' + (fund === c.id ? ' on' : '')} onClick={() => { setFund(c.id); setPage(1); }}>
                <span className="sw" style={{ width: 8, height: 8, borderRadius: 2, background: funds.colorOf(c.id) }} />{c.name.replace(/^เงิน/, '')}
              </button>
            ))}
          </div>
        </div>
        <div className="row wrap" style={{ padding: '12px 16px', gap: 10 }}>
          <div className="search"><Icon name="search" size={16} /><input value={qLive} onChange={e => setQLive(e.target.value)} placeholder="ค้นหาเลขที่รายการ โครงการ หรือเอกสารอ้างอิง" aria-label="ค้นหา" /></div>
          <select className="select" value={type} onChange={e => { setType(e.target.value); setPage(1); }} aria-label="ประเภทรายการ">
            <option value="">ทุกประเภท</option>
            {Object.entries(meta.ledger_types).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
          </select>
          <div style={{ flex: 1 }} />
          {fund && !cards.find(c => c.id === fund) && funds.byId[fund] && <span className="chip on" onClick={() => setFund('')}>{funds.label(fund)} ✕</span>}
          {params.project_id && <span className="chip on" onClick={() => navigate('ledger')}>เฉพาะโครงการที่เลือก ✕</span>}
        </div>
        {error && <div style={{ padding: '0 16px 16px' }}><LoadError error={error} onRetry={reload} /></div>}
        <div className="table-wrap" style={{ borderTop: '1px solid var(--line)', opacity: loading ? .6 : 1 }}>
          <table className="tbl" style={{ minWidth: 1100 }}>
            <thead><tr>
              <th>เลขที่รายการ</th><th>วันที่</th><th>ประเภท</th><th>จาก → ไป</th><th>แหล่งเงิน</th><th>หมวด</th>
              <th className="th-num">จำนวน (บาท)</th><th>เอกสารอ้างอิง</th><th>ผู้บันทึก</th>
            </tr></thead>
            <tbody>
              {data && data.rows.map(r => {
                const struck = !!r.reversed_by_id;
                return (
                  <tr key={r.id} className={'click' + (struck ? ' struck' : '')} onClick={() => setOpen(r)}>
                    <td className="mono nowrap" style={{ fontSize: 12, color: 'var(--text-2)' }}>{r.entry_no}{r.attachment_count > 0 && <span title="มีไฟล์แนบ"> <Icon name="paperclip" size={12} /></span>}</td>
                    <td className="nowrap sm">{thDate(r.entry_date)}</td>
                    <td><TypeBadge type={r.entry_type} label={r.type_label} /></td>
                    <td className="sm">
                      <div className={struck ? 'struck-text' : ''}>{r.from} → {r.to}{r.note && r.entry_type !== 'reversal' ? <span className="muted"> · {r.note}</span> : null}</div>
                      {struck && <div className="xs" style={{ color: 'var(--red-text)', marginTop: 2 }}>ถูกกลับรายการโดย <a className="mono" onClick={e => { e.stopPropagation(); jump(r.reversed_by_no); }}>{r.reversed_by_no}</a></div>}
                      {r.reverses_entry_id && <div className="xs muted" style={{ marginTop: 2 }}>กลับรายการของ <a className="mono" onClick={e => { e.stopPropagation(); jump(r.reverses_no); }}>{r.reverses_no}</a> · {r.note}</div>}
                      {r.override_reason && <div className="xs" style={{ color: 'var(--orange-fg)', marginTop: 2 }}>ยืนยันข้าม BR-22: {r.override_reason}</div>}
                    </td>
                    <td className="nowrap sm"><FundTag color={funds.colorOf(r.fund_source_id)}>{r.fund_name}</FundTag></td>
                    <td className="sm" style={{ color: 'var(--text-3)' }}>{r.category_name || '—'}</td>
                    <td className="num" style={{ fontWeight: 600, color: r.signed > 0 ? 'var(--green-fg)' : 'var(--text)', textDecoration: struck ? 'line-through' : 'none' }}>
                      {r.signed ? fmtSigned(r.signed) : fmt(r.amount)}
                    </td>
                    <td className="sm nowrap" style={{ color: 'var(--link)' }}>{r.reference_no || '—'}</td>
                    <td className="sm nowrap" style={{ color: 'var(--text-3)' }}>{r.created_by_name}</td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
        {data && data.rows.length === 0 && <div style={{ padding: '36px 16px', textAlign: 'center' }} className="muted">ไม่พบรายการตามเงื่อนไข</div>}
        {!data && loading && <div className="card-b"><div className="skel" style={{ height: 14 }} /></div>}
        {data && (
          <div className="pager">
            <span>แสดง {data.rows.length} รายการจาก {fmt0(data.total)} รายการในปีงบประมาณ {meta.fiscal_year.year_be}</span>
            <Pager page={data.page} per={data.per} total={data.total} onPage={setPage} />
          </div>
        )}
      </div>
      {open && <EntryDrawer entry={open} onClose={() => setOpen(null)} onChanged={() => { setOpen(null); reload(); }} />}
    </div>
  );
}

function EntryDrawer({ entry, onClose, onChanged }) {
  const { toast, funds, meta } = useApp();
  const { data } = useApi('ledger/entry', { id: entry.id });
  const [reason, setReason] = useState('');
  const [tried, setTried] = useState(false);
  const [busy, run] = useBusy();
  const [file, setFile] = useState(null);
  const d = data || entry;
  useEffect(() => {
    const k = e => { if (e.key === 'Escape') onClose(); };
    window.addEventListener('keydown', k);
    return () => window.removeEventListener('keydown', k);
  }, []);
  const reverse = () => run(async () => {
    setTried(true);
    if (!reason.trim()) return;
    try {
      const r = await post('ledger/reverse', { id: entry.id, reason });
      toast('กลับรายการ ' + entry.entry_no + ' แล้ว (' + r.entry.entry_no + ')', 'ok');
      onChanged();
    } catch (e) { toast(e.message, 'err'); }
  });
  const attach = () => run(async () => {
    const fd = new FormData();
    fd.append('entry_id', entry.id); fd.append('kind', 'other'); fd.append('file', file);
    try { await api('ledger/attach', { method: 'POST', form: fd }); toast('แนบไฟล์แล้ว', 'ok'); onChanged(); } catch (e) { toast(e.message, 'err'); }
  });
  const rows = [
    ['ประเภท', <TypeBadge type={d.entry_type} label={d.type_label} />],
    ['วันที่', thDate(d.entry_date)], ['บันทึกเมื่อ', thDate(d.posted_at, true)],
    ['จาก', d.from], ['ไป', d.to],
    ['แหล่งเงิน', <FundTag color={funds.colorOf(d.fund_source_id)}>{funds.label(d.fund_source_id)}</FundTag>],
    ['หมวด', d.category_name || '—'], ['เอกสารอ้างอิง', (d.reference_no || '—') + (d.reference_date ? ' · ' + thDate(d.reference_date) : '')],
    ['งวดที่', d.installment_no || '—'], ['หมายเหตุ', d.note || '—'], ['ผู้บันทึก', d.created_by_name],
  ];
  if (d.override_reason) rows.push(['ยืนยันข้าม BR-22', d.override_reason]);
  return (
    <Fragment>
      <div className="backdrop" style={{ zIndex: 70 }} onClick={onClose} />
      <aside className="drawer" role="dialog" aria-label={'รายการ ' + entry.entry_no}>
        <div style={{ padding: '16px 20px', borderBottom: '1px solid var(--line-soft)', display: 'flex', gap: 12, alignItems: 'flex-start', position: 'sticky', top: 0, background: 'var(--surface)' }}>
          <div className="grow">
            <div className="mono sm muted">{entry.entry_no}</div>
            <div style={{ fontSize: 22, fontWeight: 700, color: 'var(--heading)' }}>{d.signed ? fmtSigned(d.signed) : fmt(d.amount)} <span className="sm muted" style={{ fontWeight: 400 }}>บาท</span></div>
          </div>
          <button className="icon-btn plain" onClick={onClose} title="ปิด (Esc)"><Icon name="x" size={16} /></button>
        </div>
        <div style={{ padding: '16px 20px', display: 'flex', flexDirection: 'column', gap: 18 }}>
          {d.reversed_by_id && <Alert tone="red">รายการนี้ถูกกลับรายการแล้วโดย <b className="mono">{d.reversed_by_no}</b></Alert>}
          {d.reverses_entry_id && <Alert tone="blue">รายการนี้กลับรายการของ <b className="mono">{d.reverses_no}</b></Alert>}
          <div>{rows.map(([k, v]) => (
            <div key={k} style={{ display: 'grid', gridTemplateColumns: '130px minmax(0,1fr)', gap: 12, padding: '8px 0', borderBottom: '1px solid var(--row-line)', fontSize: 13 }}>
              <span className="muted">{k}</span><span style={{ lineHeight: 1.5 }}>{v}</span>
            </div>
          ))}</div>
          <div>
            <div className="sm" style={{ fontWeight: 600, color: 'var(--muted)', marginBottom: 6 }}>ไฟล์แนบ</div>
            {data && data.attachments && data.attachments.length === 0 && <div className="sm muted">ไม่มีไฟล์แนบ</div>}
            {data && data.attachments && data.attachments.map(a => (
              <div key={a.id} className="row sm" style={{ padding: '5px 0' }}>
                <Icon name="paperclip" size={14} /><a href={apiUrl('ledger/attachment', { id: a.id })}>{a.original_name}</a>
                <span className="xs muted">{(a.size_bytes / 1024).toFixed(0)} KB · {a.uploaded_by_name}</span>
              </div>
            ))}
            {entry.can_reverse && (
              <div className="row" style={{ marginTop: 8 }}>
                <input type="file" accept=".pdf,.docx,.xlsx,.jpg,.jpeg,.png" onChange={e => setFile(e.target.files[0] || null)} style={{ fontSize: 12.5, minWidth: 0 }} />
                <button className="btn sm" disabled={!file || busy} onClick={attach}>แนบ</button>
              </div>
            )}
          </div>
          {entry.can_reverse && (
            <div style={{ padding: 14, borderRadius: 8, border: '1px solid var(--orange-line)', background: 'var(--orange-soft)', display: 'flex', flexDirection: 'column', gap: 10 }}>
              <div style={{ fontWeight: 600, color: 'var(--orange-fg)' }}>กลับรายการ</div>
              <div className="sm" style={{ color: 'var(--orange-fg)' }}>ระบบจะสร้างรายการใหม่ที่สลับต้นทาง/ปลายทาง ยอดคงเหลือจะกลับเป็นเหมือนก่อนบันทึกรายการนี้ รายการเดิมยังคงอยู่และแสดงเป็นขีดฆ่า</div>
              <Field label="เหตุผล" required error={tried && !reason.trim() ? 'ต้องระบุเหตุผล' : null}>
                <textarea className={'textarea' + (tried && !reason.trim() ? ' err' : '')} rows={3} value={reason} onChange={e => setReason(e.target.value)} placeholder="เช่น บันทึกจำนวนผิด ใบเสร็จระบุ 2,500.00 บาท" />
              </Field>
              <div className="row" style={{ justifyContent: 'flex-end' }}><button className="btn warn" disabled={busy} onClick={reverse}>ยืนยันกลับรายการ</button></div>
            </div>
          )}
        </div>
      </aside>
    </Fragment>
  );
}

Object.assign(window, { LedgerPage, EntryDrawer });
