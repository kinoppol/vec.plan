// funds.jsx — fund overview (5.15), revenue estimates & receipts (5.16), carry-in / adjustments / reserves

/** Leaf fund picker grouped by top-level fund. */
function FundSelect({ value, onChange, invalid, allowAll, allowGroups, id }) {
  const { funds } = useApp();
  const opts = [];
  const walk = (f, depth) => {
    if (f.is_leaf || allowGroups) opts.push({ f, depth, disabled: !f.is_leaf && !allowGroups });
    else opts.push({ f, depth, disabled: true });
    f.children.sort((a, b) => a.sort - b.sort || a.id - b.id).forEach(c => walk(c, depth + 1));
  };
  funds.roots.sort((a, b) => a.sort - b.sort || a.id - b.id).forEach(r => walk(r, 0));
  return (
    <select id={id} className={'select' + (invalid ? ' err' : '')} value={value || ''} onChange={e => onChange(e.target.value ? +e.target.value : '')}>
      <option value="">{allowAll ? 'ทุกแหล่งเงิน' : '— เลือกแหล่งเงิน —'}</option>
      {opts.map(({ f, depth, disabled }) => (
        <option key={f.id} value={f.id} disabled={disabled || (!f.active && f.is_leaf)}>
          {'  '.repeat(depth)}{f.name}{f.is_leaf ? ' (' + f.code + ')' : ''}{!f.active ? ' — ปิดใช้งาน' : ''}
        </option>
      ))}
    </select>
  );
}

// ------------------------------------------------------------------ overview
function FundsPage() {
  const { meta } = useApp();
  const { data, error, loading, reload } = useApi('funds/positions');
  const [open, setOpen] = useState({});
  if (!meta.permissions.view_funds) return <Forbidden />;
  const head = (
    <PageHead crumb="กองเงิน" title="ภาพรวมกองเงิน">
      {meta.permissions.view_ledger && <button className="btn" onClick={() => download('ledger/export')}><Icon name="download" size={16} />ส่งออกสมุดบัญชี (Excel)</button>}
      {meta.permissions.post_receipt && <button className="btn primary" onClick={() => navigate('receive')}>+ บันทึกรับเงิน</button>}
    </PageHead>
  );
  if (loading && !data) return <div className="stack">{head}<Skeleton /></div>;
  if (error) return <div className="stack">{head}<LoadError error={error} onRetry={reload} /></div>;
  const t = data.totals;
  return (
    <div className="stack">
      {head}
      <div className="card card-b row wrap" style={{ gap: '8px 28px', fontSize: 13 }}>
        <span className="muted">รวมทุกกอง</span>
        <span>ยกมา <b>{fmt(t.carry_in)}</b></span>
        <span>ประมาณการ <b>{fmt(t.estimate)}</b></span>
        <span>รับจริง <b>{fmt(t.receipts)}</b></span>
        <span>จัดสรร <b>{fmt(t.allocated)}</b></span>
        <span>คงเหลือจัดสรรได้ (จริง) <b style={{ color: t.pool_actual < 0 ? 'var(--red-text)' : 'var(--green-fg)' }}>{fmt(t.pool_actual)}</b></span>
        <span className="muted">(ตามประมาณการ) <b>{fmt(t.pool_estimate)}</b></span>
      </div>
      <div className="grid-cards">
        {data.cards.map(f => {
          const diff = f.receipts - f.estimate_to_date;
          const mx = Math.max(f.receipts, f.estimate_to_date, 1) * 1.08;
          const isOpen = !!open[f.id];
          const metrics = [['ยกมา', f.carry_in], ['ประมาณการ', f.estimate], ['รับจริง', f.receipts], ['ส่วนต่างถึงงวด', diff, true],
            ['ปรับจัดสรร', f.adjust_up - f.adjust_down, true], ['กันไว้', f.reserved], ['จัดสรร', f.allocated], ['ผูกพัน', f.committed_open], ['จ่าย', f.spent], ['คืนกอง', f.returns]];
          return (
            <div key={f.id} className="card" style={{ display: 'flex', flexDirection: 'column' }}>
              <div style={{ height: 4, borderRadius: '10px 10px 0 0', background: f.color }} />
              <div className="row" style={{ padding: '14px 16px 0', justifyContent: 'space-between' }}>
                <span style={{ fontSize: 14.5, fontWeight: 600, color: 'var(--heading)' }}>{f.name}</span>
                <span className="xs mono muted">{f.code}</span>
              </div>
              <div style={{ padding: '10px 16px 14px', display: 'flex', flexDirection: 'column', gap: 12 }}>
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(2,minmax(0,1fr))', gap: 10 }}>
                  <div style={{ padding: 10, borderRadius: 8, background: 'var(--sel)', border: '1px solid var(--line)' }}>
                    <div className="xs" style={{ color: 'var(--text-2)' }}>คงเหลือจัดสรรได้ (จริง)</div>
                    <div style={{ fontSize: 16, fontWeight: 700, color: f.pool_actual < 0 ? 'var(--red-text)' : 'var(--heading)' }}>{fmt(f.pool_actual)}</div>
                  </div>
                  <div style={{ padding: 10, borderRadius: 8, border: '1px dashed var(--input-line)' }}>
                    <div className="xs muted">(ตามประมาณการ)</div>
                    <div style={{ fontSize: 16, fontWeight: 600, color: 'var(--muted)' }}>{fmt(f.pool_estimate)}</div>
                  </div>
                </div>
                <div className="stack" style={{ gap: 5 }}>
                  <div className="row xs muted" style={{ justifyContent: 'space-between' }}>
                    <span>รับจริงเทียบประมาณการถึงงวด</span>
                    <span style={{ fontWeight: 600, color: diff < 0 ? 'var(--orange-fg)' : 'var(--green-fg)' }}>{fmtSigned(diff)}</span>
                  </div>
                  <div style={{ position: 'relative', height: 10, borderRadius: 3, background: 'var(--track)' }} title={'รับจริง ' + fmt(f.receipts) + ' · ประมาณการถึงงวด ' + fmt(f.estimate_to_date)}>
                    <div style={{ position: 'absolute', left: 0, top: 0, bottom: 0, width: pct(f.receipts, mx) + '%', background: f.color, borderRadius: 3 }} />
                    <div style={{ position: 'absolute', left: 0, top: -3, bottom: -3, width: pct(f.estimate_to_date, mx) + '%', border: '1.5px dashed var(--text-2)', borderRadius: 3 }} />
                  </div>
                </div>
                <div className="metric-list">
                  {metrics.map(([k, v, signed]) => <div key={k}><span>{k}</span><span>{signed ? fmtSigned(v) : fmt(v)}</span></div>)}
                </div>
                <div className="row" style={{ justifyContent: 'space-between' }}>
                  <button className="btn link sm row" onClick={() => setOpen(o => ({ ...o, [f.id]: !o[f.id] }))} aria-expanded={isOpen}>
                    <Icon name="chevron" size={14} stroke={2} style={{ transform: isOpen ? 'rotate(90deg)' : 'none' }} />กองย่อย ({f.leaves.length})
                  </button>
                  {meta.permissions.view_ledger && <a className="sm" onClick={() => navigate('ledger', { fund: f.id })}>ดูรายการในสมุดบัญชี</a>}
                </div>
                {isOpen && (
                  <div style={{ borderLeft: '2px solid ' + f.color, paddingLeft: 12 }}>
                    <table className="tbl dense" style={{ fontSize: 12.5 }}>
                      <thead><tr><th>กองย่อย</th><th className="th-num">รับจริง</th><th className="th-num">จัดสรร</th><th className="th-num">คงเหลือ (จริง)</th></tr></thead>
                      <tbody>
                        {f.leaves.map(l => (
                          <tr key={l.id} className={meta.permissions.view_ledger ? 'click' : ''} onClick={() => meta.permissions.view_ledger && navigate('ledger', { fund: l.id })}>
                            <td style={{ fontSize: 12.5 }}>{l.parent_name ? <span className="muted">{l.parent_name} › </span> : null}{l.name}</td>
                            <td className="num" style={{ fontSize: 12.5 }}>{fmt(l.receipts + l.carry_in)}</td>
                            <td className="num" style={{ fontSize: 12.5 }}>{fmt(l.allocated)}</td>
                            <td className="num" style={{ fontSize: 12.5, fontWeight: 600, color: l.pool_actual < 0 ? 'var(--red-text)' : 'inherit' }}>{fmt(l.pool_actual)}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}
              </div>
            </div>
          );
        })}
      </div>
      {data.reserves.length > 0 && (
        <div className="card">
          <div className="card-h"><h3>เงินกันไว้</h3>{(meta.permissions.post_reserve) && <a className="sm" onClick={() => navigate('fund-entry', { type: 'reserve' })}>กันเงิน / ปล่อยเงินกัน</a>}</div>
          <div className="table-wrap">
            <table className="tbl">
              <thead><tr><th>รายการกันเงิน</th><th>แหล่งเงิน</th><th className="th-num">คงเหลือ</th></tr></thead>
              <tbody>{data.reserves.map(r => <tr key={r.id}><td>{r.reserve_name}</td><td>{r.fund_name} <span className="xs mono muted">{r.fund_code}</span></td><td className="num">{fmt(r.balance_num)}</td></tr>)}</tbody>
            </table>
          </div>
        </div>
      )}
      <div className="xs muted">ข้อมูล ณ {thDate(data.as_of, true)} · ยอดคำนวณจากสมุดบัญชีทุกครั้งที่เปิดหน้า (BR-24)</div>
    </div>
  );
}

// ------------------------------------------------------------------ revenue estimates
function EstimatesPage() {
  const { meta, funds, toast } = useApp();
  const { data, error, loading, reload } = useApi('funds/estimates');
  const [edit, setEdit] = useState(null);
  const [busy, run] = useBusy();
  if (!meta.permissions.view_funds) return <Forbidden />;
  const can = meta.permissions.estimates;
  const head = (
    <PageHead crumb="กองเงิน" title="ประมาณการรายรับ">
      {can && <button className="btn primary" onClick={() => setEdit({ fund_source_id: '', label: '', installment_no: '', expected_month: '', amount: '', basis: { students: '', rate: '', terms: '' } })}>+ เพิ่มรายการประมาณการ</button>}
    </PageHead>
  );
  if (loading && !data) return <div className="stack">{head}<Skeleton /></div>;
  if (error) return <div className="stack">{head}<LoadError error={error} onRetry={reload} /></div>;

  // Group rows: leaf fund → versions
  const byFund = {};
  data.rows.forEach(r => { (byFund[r.fund_source_id] = byFund[r.fund_source_id] || []).push(r); });
  const leafIds = funds.leaves.map(f => f.id).filter(id => byFund[id]);
  const action = (fn, msg) => run(async () => { try { await fn(); toast(msg, 'ok'); reload(); } catch (e) { toast(e.message, 'err'); } });

  return (
    <div className="stack">
      {head}
      <Alert tone="blue">ประมาณการใช้คำนวณ "คงเหลือจัดสรรได้ (ตามประมาณการ)" (BR-10) และเทียบกับรับจริงถึงงวด — แต่ละกองย่อยมีได้หลายฉบับ (เช่น ฉบับต้นปี / ปรับกลางปี) ระบบใช้เฉพาะฉบับปัจจุบัน</Alert>
      {leafIds.length === 0 && <Empty icon="trend" title="ยังไม่มีประมาณการรายรับ">เพิ่มรายการประมาณการต่อกองเงินย่อย พร้อมฐานคำนวณ เช่น จำนวนผู้เรียน × อัตรา × ภาคเรียน</Empty>}
      {leafIds.map(fid => {
        const rows = byFund[fid];
        const versions = [...new Set(rows.map(r => r.version))].sort((a, b) => a - b);
        const current = rows.find(r => r.is_current);
        const curV = current ? current.version : null;
        const rec = data.received[fid] || { receipts: 0, estimate_to_date: 0, estimate: 0 };
        const shown = rows.filter(r => r.version === curV);
        const sum = shown.reduce((a, r) => a + r.amount, 0);
        return (
          <div key={fid} className="card">
            <div className="card-h">
              <div className="row" style={{ gap: 10 }}>
                <span className="sw" style={{ background: funds.colorOf(fid) }} />
                <h3>{funds.label(fid)}</h3>
                <span className="xs mono muted">{funds.byId[fid] && funds.byId[fid].code}</span>
              </div>
              <div className="row wrap">
                {versions.length > 1 && (
                  <select className="select" value={curV || ''} disabled={!can || busy} onChange={e => action(() => post('funds/estimate_set_current', { fund_source_id: fid, version: +e.target.value }), 'เปลี่ยนฉบับปัจจุบันแล้ว')}>
                    {versions.map(v => <option key={v} value={v}>ฉบับที่ {v}{v === 1 ? ' (ต้นปี)' : ''}</option>)}
                  </select>
                )}
                {versions.length <= 1 && <Badge bg="var(--gray-bg)" fg="var(--gray-fg)">ฉบับที่ {curV || 1}</Badge>}
                {can && <button className="btn sm" disabled={busy} onClick={() => action(() => post('funds/estimate_new_version', { fund_source_id: fid }), 'สร้างฉบับปรับปรุงใหม่แล้ว')}>สร้างฉบับปรับกลางปี</button>}
              </div>
            </div>
            <div className="table-wrap">
              <table className="tbl">
                <thead><tr><th>รายการ</th><th>งวด</th><th>เดือนที่คาดว่าจะได้รับ</th><th>ฐานคำนวณ</th><th className="th-num">จำนวน (บาท)</th>{can && <th />}</tr></thead>
                <tbody>
                  {shown.map(r => (
                    <tr key={r.id}>
                      <td>{r.label}</td>
                      <td>{r.installment_no || '—'}</td>
                      <td>{r.expected_month ? FISCAL_MONTHS[r.expected_month - 1] : '—'}</td>
                      <td className="sm muted">{r.basis ? `${fmt0(r.basis.students)} คน × ${fmt(r.basis.rate)} × ${r.basis.terms} ภาคเรียน` : '—'}</td>
                      <td className="num">{fmt(r.amount)}</td>
                      {can && <td className="nowrap"><button className="btn sm" onClick={() => setEdit({ ...r, amount: fmt(r.amount), basis: r.basis || { students: '', rate: '', terms: '' } })}>แก้ไข</button></td>}
                    </tr>
                  ))}
                </tbody>
                <tfoot><tr><td colSpan={4}>รวมฉบับปัจจุบัน</td><td className="num">{fmt(sum)}</td>{can && <td />}</tr></tfoot>
              </table>
            </div>
            <div className="card-b row wrap sm muted" style={{ gap: '6px 20px' }}>
              <span>รับจริงสะสม <b style={{ color: 'var(--text)' }}>{fmt(rec.receipts)}</b></span>
              <span>ประมาณการถึงงวด <b style={{ color: 'var(--text)' }}>{fmt(rec.estimate_to_date)}</b></span>
              <span>ส่วนต่าง <b style={{ color: rec.receipts - rec.estimate_to_date < 0 ? 'var(--orange-fg)' : 'var(--green-fg)' }}>{fmtSigned(rec.receipts - rec.estimate_to_date)}</b></span>
            </div>
          </div>
        );
      })}
      {edit && <EstimateEditor row={edit} onClose={() => setEdit(null)} onSaved={() => { setEdit(null); reload(); }} />}
    </div>
  );
}

function EstimateEditor({ row, onClose, onSaved }) {
  const { toast } = useApp();
  const [f, setF] = useState(row);
  const [useBasis, setUseBasis] = useState(!!(row.basis && row.basis.students));
  const [err, setErr] = useState('');
  const [busy, run] = useBusy();
  const basisTotal = (+f.basis.students || 0) * (parseMoney(f.basis.rate) || 0) * (+f.basis.terms || 0);
  const save = () => run(async () => {
    setErr('');
    try {
      await post('funds/estimate_save', {
        id: f.id, fund_source_id: f.fund_source_id, label: f.label, installment_no: f.installment_no, expected_month: f.expected_month,
        amount: useBasis ? undefined : parseMoney(f.amount),
        basis: useBasis ? { students: +f.basis.students, rate: parseMoney(f.basis.rate), terms: +f.basis.terms } : null,
      });
      toast('บันทึกประมาณการแล้ว', 'ok');
      onSaved();
    } catch (e) { setErr(e.message); }
  });
  const del = () => run(async () => {
    if (!confirm('ลบรายการประมาณการนี้?')) return;
    try { await post('funds/estimate_delete', { id: f.id }); toast('ลบแล้ว', 'ok'); onSaved(); } catch (e) { setErr(e.message); }
  });
  return (
    <Modal title={f.id ? 'แก้ไขประมาณการ' : 'เพิ่มประมาณการรายรับ'} onClose={onClose} footer={<Fragment>
      {f.id && <button className="btn danger" onClick={del} disabled={busy} style={{ marginRight: 'auto' }}>ลบรายการ</button>}
      <button className="btn" onClick={onClose}>ยกเลิก</button>
      <button className="btn primary" onClick={save} disabled={busy}>บันทึก</button>
    </Fragment>}>
      <Field label="กองเงินย่อย" required><FundSelect value={f.fund_source_id} onChange={v => setF({ ...f, fund_source_id: v })} /></Field>
      <Field label="รายการ" required><input className="input" value={f.label} onChange={e => setF({ ...f, label: e.target.value })} placeholder="เช่น ค่าบำรุงการศึกษา ภาคเรียนที่ 1" /></Field>
      <div className="form-grid">
        <Field label="งวดที่"><input className="input" type="number" min="1" value={f.installment_no || ''} onChange={e => setF({ ...f, installment_no: e.target.value })} /></Field>
        <Field label="เดือนที่คาดว่าจะได้รับ" hint="ใช้คำนวณประมาณการถึงงวด">
          <select className="select" value={f.expected_month || ''} onChange={e => setF({ ...f, expected_month: e.target.value })}>
            <option value="">ไม่ระบุ (นับทั้งหมด)</option>{FISCAL_MONTHS.map((m, i) => <option key={i} value={i + 1}>{m}</option>)}
          </select>
        </Field>
      </div>
      <label className="check"><input type="checkbox" checked={useBasis} onChange={e => setUseBasis(e.target.checked)} /> คำนวณจากฐาน (จำนวนผู้เรียน × อัตรา × ภาคเรียน)</label>
      {useBasis ? (
        <div className="form-grid">
          <Field label="จำนวนผู้เรียน"><input className="input num" type="number" min="0" value={f.basis.students} onChange={e => setF({ ...f, basis: { ...f.basis, students: e.target.value } })} /></Field>
          <Field label="อัตรา (บาท/คน/ภาค)"><MoneyInput value={f.basis.rate} onChange={v => setF({ ...f, basis: { ...f.basis, rate: v } })} /></Field>
          <Field label="จำนวนภาคเรียน"><input className="input num" type="number" min="1" value={f.basis.terms} onChange={e => setF({ ...f, basis: { ...f.basis, terms: e.target.value } })} /></Field>
          <Field label="รวม (บาท)"><input className="input num" readOnly value={fmt(basisTotal)} style={{ fontWeight: 600, background: 'var(--surface-2)' }} /></Field>
        </div>
      ) : (
        <Field label="จำนวน (บาท)" required><MoneyInput value={f.amount} onChange={v => setF({ ...f, amount: v })} /></Field>
      )}
      {err && <Alert tone="red">{err}</Alert>}
    </Modal>
  );
}

// ------------------------------------------------------------------ receipts (5.16)
function useFundPosition(fundId, deps) {
  const { data, reload } = useApi('funds/positions', null, deps);
  if (!data || !fundId) return [null, reload];
  for (const c of data.cards) for (const l of c.leaves) if (l.id === +fundId) return [l, reload];
  return [null, reload];
}

function ReceivePage() {
  const { meta, toast } = useApp();
  const blank = { fund_source_id: '', installment_no: '', entry_date: meta.today, amount: '', reference_no: '', reference_date: meta.today, note: '' };
  const [f, setF] = useState(blank);
  const [file, setFile] = useState(null);
  const [done, setDone] = useState(null);
  const [errs, setErrs] = useState({});
  const [busy, run] = useBusy();
  const [pos, reloadPos] = useFundPosition(f.fund_source_id, [done && done.entry.id]);
  const fileRef = useRef();
  if (!meta.permissions.post_receipt) return <Forbidden />;
  const amount = parseMoney(f.amount);

  const save = () => run(async () => {
    const e = {};
    if (!f.fund_source_id) e.fund = 'เลือกกองเงิน';
    if (!(amount > 0)) e.amount = 'จำนวนเงินต้องมากกว่า 0';
    if (!f.reference_no.trim()) e.ref = 'ต้องระบุเลขที่หนังสือ/ใบเสร็จ (BR-23)';
    if (!f.reference_date) e.refdate = 'ต้องระบุวันที่เอกสาร (BR-23)';
    if (!f.entry_date) e.date = 'ต้องระบุวันที่เงินเข้า';
    setErrs(e);
    if (Object.keys(e).length) return;
    try {
      const r = await post('ledger/post', { ...f, entry_type: 'receipt', amount });
      if (file) {
        const fd = new FormData();
        fd.append('entry_id', r.entry.id); fd.append('kind', 'receipt'); fd.append('file', file);
        try { await api('ledger/attach', { method: 'POST', form: fd }); } catch (ex) { toast('บันทึกรับเงินแล้ว แต่แนบไฟล์ไม่สำเร็จ: ' + ex.message, 'err'); }
      }
      setDone(r);
      toast('บันทึกรับเงิน ' + r.entry.entry_no + ' แล้ว', 'ok');
      setF({ ...blank, fund_source_id: f.fund_source_id, entry_date: f.entry_date, reference_date: f.reference_date });
      setFile(null);
      if (fileRef.current) fileRef.current.value = '';
      reloadPos();
    } catch (ex) { toast(ex.message, 'err'); }
  });

  return (
    <div className="stack">
      <PageHead crumb="กองเงิน" title="บันทึกรับเงิน">
        <button className="btn" onClick={() => navigate('ledger', { type: 'receipt' })}>รายการรับเงินทั้งหมด</button>
      </PageHead>
      <div className="grid-auto">
        <div className="card">
          <div className="card-h"><h3>รายการรับเงิน</h3><span className="xs muted">บันทึกต่อเนื่องได้ · ค่าแหล่งเงินและวันที่คงไว้</span></div>
          <div className="card-b stack" style={{ gap: 14 }}>
            <Field label="กองเงิน (ย่อยสุด)" required error={errs.fund}><FundSelect value={f.fund_source_id} onChange={v => setF({ ...f, fund_source_id: v })} invalid={errs.fund} /></Field>
            <div className="form-grid">
              <Field label="งวดที่"><input className="input" type="number" min="1" value={f.installment_no} onChange={e => setF({ ...f, installment_no: e.target.value })} /></Field>
              <Field label="จำนวน (บาท)" required error={errs.amount}><MoneyInput value={f.amount} onChange={v => setF({ ...f, amount: v })} invalid={errs.amount} /></Field>
            </div>
            <Field label="วันที่เงินเข้า" required error={errs.date}><ThaiDateInput value={f.entry_date} onChange={v => setF({ ...f, entry_date: v })} invalid={errs.date} /></Field>
            <div className="form-grid">
              <Field label="เลขที่หนังสือ/ใบเสร็จ" required error={errs.ref}><input className={'input' + (errs.ref ? ' err' : '')} value={f.reference_no} onChange={e => setF({ ...f, reference_no: e.target.value })} placeholder="เช่น ศธ 0606/1453" /></Field>
            </div>
            <Field label="วันที่เอกสาร" required error={errs.refdate}><ThaiDateInput value={f.reference_date} onChange={v => setF({ ...f, reference_date: v })} invalid={errs.refdate} /></Field>
            <Field label="รายละเอียด / ที่มา"><input className="input" value={f.note} onChange={e => setF({ ...f, note: e.target.value })} placeholder="เช่น สอศ. → กองเงินอุดหนุน" /></Field>
            <Field label="แนบไฟล์หลักฐาน" hint="PDF, DOCX, XLSX, JPG, PNG ไม่เกิน 20 MB">
              <input ref={fileRef} type="file" accept=".pdf,.docx,.xlsx,.jpg,.jpeg,.png" onChange={e => setFile(e.target.files[0] || null)} />
            </Field>
            <div className="row" style={{ justifyContent: 'flex-end' }}>
              <button className="btn primary" onClick={save} disabled={busy}>{busy ? 'กำลังบันทึก…' : 'บันทึกรับเงิน'}</button>
            </div>
          </div>
        </div>
        <div className="stack">
          <div className="card">
            <div className="card-h"><h3>ผลกระทบต่อกองเงิน</h3></div>
            <div className="card-b">
              {!pos && <div className="sm muted">เลือกกองเงินเพื่อดูรับจริงสะสมเทียบประมาณการ</div>}
              {pos && (() => {
                const after = pos.receipts + (amount > 0 ? amount : 0);
                const mx = Math.max(after, pos.estimate, 1);
                return (
                  <div className="stack" style={{ gap: 10 }}>
                    <div className="row" style={{ justifyContent: 'space-between' }}><span className="sm">{pos.name}</span><span className="xs mono muted">{pos.code}</span></div>
                    <div style={{ position: 'relative', height: 14, borderRadius: 4, background: 'var(--track)' }}>
                      <div style={{ position: 'absolute', left: 0, top: 0, bottom: 0, width: pct(after, mx) + '%', background: 'var(--navy)', opacity: .35, borderRadius: 4 }} />
                      <div style={{ position: 'absolute', left: 0, top: 0, bottom: 0, width: pct(pos.receipts, mx) + '%', background: 'var(--navy)', borderRadius: 4 }} />
                      <div style={{ position: 'absolute', top: -4, bottom: -4, left: pct(pos.estimate_to_date, mx) + '%', width: 0, borderLeft: '2px dashed var(--text-2)' }} title="ประมาณการถึงงวด" />
                    </div>
                    <div className="metric-list">
                      <div><span>รับจริงสะสม (ก่อน)</span><span>{fmt(pos.receipts)}</span></div>
                      <div><span>หลังบันทึกนี้</span><span style={{ color: 'var(--green-fg)' }}>{fmt(after)}</span></div>
                      <div><span>ประมาณการถึงงวด</span><span>{fmt(pos.estimate_to_date)}</span></div>
                      <div><span>ประมาณการทั้งปี</span><span>{fmt(pos.estimate)}</span></div>
                      <div><span>คงเหลือจัดสรรได้ (จริง)</span><span>{fmt(pos.pool_actual)} → {fmt(pos.pool_actual + (amount > 0 ? amount : 0))}</span></div>
                    </div>
                  </div>
                );
              })()}
            </div>
          </div>
          {done && (
            <Alert tone="green" title={'บันทึก ' + done.entry.entry_no + ' แล้ว'}
              action={meta.permissions.view_ledger && <a className="sm nowrap" style={{ color: 'inherit', fontWeight: 600 }} onClick={() => navigate('ledger', { q: done.entry.entry_no })}>ดูในสมุดบัญชี</a>}>
              {fmt(done.entry.amount)} บาท · รับจริงสะสม {fmt(done.before ? done.before.receipts : 0)} → {fmt(done.after ? done.after.receipts : 0)}
            </Alert>
          )}
        </div>
      </div>
    </div>
  );
}

// ------------------------------------------------------------------ carry-in / allocation adjustments / reserves
const FUND_ENTRY_TYPES = [
  { key: 'carry_in', perm: 'post_carry_in', label: 'ตั้งยอดยกมา', desc: 'ยอดคงเหลือยกมาจากปีก่อน', reduces: false },
  { key: 'allocation_adjust_up', perm: 'post_allocation_adjust_up', label: 'ปรับเพิ่มจัดสรร', desc: 'หนังสือแจ้งจัดสรรเพิ่ม', reduces: false },
  { key: 'allocation_adjust_down', perm: 'post_allocation_adjust_down', label: 'ปรับลดจัดสรร', desc: 'ถูกปรับลด/ส่งคืน', reduces: true },
  { key: 'reserve', perm: 'post_reserve', label: 'กันเงิน', desc: 'กันไว้จากกองเงิน', reduces: true },
  { key: 'return', perm: 'post_return_reserve', label: 'ปล่อยเงินกัน', desc: 'คืนเงินกันเข้ากอง', reduces: false },
];
function FundEntryPage() {
  const { meta, toast } = useApp();
  const { params } = parseHash();
  const types = FUND_ENTRY_TYPES.filter(t => meta.permissions[t.perm]);
  const [type, setType] = useState(types.find(t => t.key === params.type) ? params.type : (types[0] && types[0].key));
  const blank = { fund_source_id: '', amount: '', entry_date: meta.today, reference_no: '', reserve_name: '', from_reserve: '', note: '' };
  const [f, setF] = useState(blank);
  const [busy, run] = useBusy();
  const [last, setLast] = useState(null);
  const [pos, reloadPos] = useFundPosition(f.fund_source_id, [last]);
  const reserves = useApi('funds/positions', null, [last]).data;
  if (!types.length) return <Forbidden />;
  const def = FUND_ENTRY_TYPES.find(t => t.key === type);
  const amount = parseMoney(f.amount);
  const fundReserves = reserves ? reserves.reserves.filter(r => +r.fund_source_id === +f.fund_source_id) : [];
  const reserveBal = fundReserves.find(r => r.reserve_name === f.from_reserve);
  const short = def.reduces && pos && amount > 0 ? amount - pos.pool_actual : type === 'return' && reserveBal && amount > 0 ? amount - reserveBal.balance_num : 0;

  const save = () => run(async () => {
    try {
      const r = await post('ledger/post', { ...f, entry_type: type, amount });
      toast(def.label + ' ' + r.entry.entry_no + ' แล้ว', 'ok');
      setLast(r.entry.id);
      setF({ ...blank, fund_source_id: f.fund_source_id, entry_date: f.entry_date });
      reloadPos();
    } catch (e) { toast(e.message, 'err'); }
  });
  return (
    <div className="stack">
      <PageHead crumb="กองเงิน" title="ยกมา / ปรับจัดสรร / กันเงิน" />
      <div className="card card-b">
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(160px,1fr))', gap: 8 }}>
          {types.map(t => (
            <button key={t.key} onClick={() => setType(t.key)} style={{ textAlign: 'left', padding: '10px 12px', borderRadius: 8, cursor: 'pointer', display: 'flex', flexDirection: 'column', gap: 2,
              border: '1.5px solid ' + (type === t.key ? 'var(--navy)' : 'var(--line)'), background: type === t.key ? 'var(--sel)' : 'var(--surface)' }}>
              <span style={{ fontSize: 13.5, fontWeight: 600, color: 'var(--heading)' }}>{t.label}</span>
              <span className="xs muted">{t.desc}</span>
            </button>
          ))}
        </div>
      </div>
      <div className="grid-auto">
        <div className="card card-b stack" style={{ gap: 14 }}>
          <Field label="กองเงิน (ย่อยสุด)" required><FundSelect value={f.fund_source_id} onChange={v => setF({ ...f, fund_source_id: v, from_reserve: '' })} /></Field>
          {type === 'reserve' && <Field label="ชื่อรายการกันเงิน" required hint="ใช้ชื่อเดิมเพื่อกันเพิ่มในรายการเดิม"><input className="input" list="reserve-names" value={f.reserve_name} onChange={e => setF({ ...f, reserve_name: e.target.value })} placeholder="เช่น สำรองค่าสาธารณูปโภค" />
            <datalist id="reserve-names">{fundReserves.map(r => <option key={r.id} value={r.reserve_name} />)}</datalist></Field>}
          {type === 'return' && (
            <Field label="รายการกันเงินที่จะปล่อย" required>
              <select className="select" value={f.from_reserve} onChange={e => setF({ ...f, from_reserve: e.target.value })}>
                <option value="">— เลือก —</option>
                {fundReserves.filter(r => r.balance_num > 0).map(r => <option key={r.id} value={r.reserve_name}>{r.reserve_name} (คงเหลือ {fmt(r.balance_num)})</option>)}
              </select>
            </Field>
          )}
          <div className="form-grid">
            <Field label="จำนวน (บาท)" required error={short > 0 ? 'เกินยอดคงเหลือ ' + fmt(short) + ' บาท (BR-22)' : null}><MoneyInput value={f.amount} onChange={v => setF({ ...f, amount: v })} invalid={short > 0} /></Field>
            <Field label="เลขที่เอกสารอ้างอิง"><input className="input" value={f.reference_no} onChange={e => setF({ ...f, reference_no: e.target.value })} /></Field>
          </div>
          <Field label="วันที่" required><ThaiDateInput value={f.entry_date} onChange={v => setF({ ...f, entry_date: v })} /></Field>
          <Field label="หมายเหตุ / เหตุผล"><textarea className="textarea" rows={2} value={f.note} onChange={e => setF({ ...f, note: e.target.value })} /></Field>
          <div className="row" style={{ justifyContent: 'flex-end' }}><button className="btn primary" disabled={busy || short > 0 || !(amount > 0) || !f.fund_source_id} onClick={save}>บันทึก{def.label}</button></div>
        </div>
        <div className="card card-b">
          {!pos ? <div className="sm muted">เลือกกองเงินเพื่อดูยอดก่อน/หลังบันทึก</div> : (
            <div className="metric-list">
              <div><span>ยกมา</span><span>{fmt(pos.carry_in)}</span></div>
              <div><span>รับจริง</span><span>{fmt(pos.receipts)}</span></div>
              <div><span>ปรับจัดสรร (สุทธิ)</span><span>{fmtSigned(pos.adjust_up - pos.adjust_down)}</span></div>
              <div><span>กันไว้</span><span>{fmt(pos.reserved)}</span></div>
              <div><span>จัดสรรลงโครงการ</span><span>{fmt(pos.allocated)}</span></div>
              <div><span>คงเหลือจัดสรรได้ (จริง)</span><span style={{ color: pos.pool_actual < 0 ? 'var(--red-text)' : 'inherit' }}>{fmt(pos.pool_actual)}</span></div>
              <div><span>หลังบันทึก</span><span style={{ fontWeight: 700, color: short > 0 ? 'var(--red-text)' : 'var(--green-fg)' }}>
                {fmt(pos.pool_actual + (amount > 0 ? (def.reduces ? -amount : (type === 'return' || type === 'carry_in' || type === 'allocation_adjust_up' ? amount : 0)) : 0))}</span></div>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

Object.assign(window, { FundSelect, FundsPage, EstimatesPage, EstimateEditor, ReceivePage, FundEntryPage, useFundPosition });
