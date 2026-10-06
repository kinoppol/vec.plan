// import.jsx — นำเข้าแผนปีที่อนุมัติแล้วจาก Excel (phase 1): upload → validate → confirm → lock baseline

function ImportPage() {
  const { meta, toast, reloadMeta } = useApp();
  const status = useApi('import/status');
  const [result, setResult] = useState(null);
  const [created, setCreated] = useState(null);
  const [override, setOverride] = useState('');
  const [busy, run] = useBusy();
  const [lockOpen, setLockOpen] = useState(false);
  const fileRef = useRef();
  if (!meta.permissions.import) return <Forbidden />;
  const locked = status.data && status.data.baseline_locked_at;
  const needOverride = result && result.totals.some(t => t.short_by > 0);

  const upload = () => run(async () => {
    const file = fileRef.current && fileRef.current.files[0];
    if (!file) { toast('เลือกไฟล์ก่อน', 'err'); return; }
    const fd = new FormData();
    fd.append('file', file);
    setCreated(null);
    try { setResult(await api('import/analyze', { method: 'POST', form: fd })); }
    catch (e) { toast(e.message, 'err'); }
  });
  const commit = () => run(async () => {
    try {
      const r = await post('import/commit', { token: result.token, override_reason: needOverride ? override : undefined });
      setCreated(r.created);
      setResult(null);
      if (fileRef.current) fileRef.current.value = '';
      toast('นำเข้า ' + r.created.length + ' โครงการแล้ว', 'ok');
      status.reload();
    } catch (e) { toast(e.message, 'err'); }
  });
  const lock = () => run(async () => {
    try { await post('import/lock_baseline'); toast('ล็อกแผนตั้งต้นแล้ว', 'ok'); setLockOpen(false); status.reload(); reloadMeta(); }
    catch (e) { toast(e.message, 'err'); }
  });
  const approved = status.data ? status.data.approved : [];
  const approvedCount = approved.reduce((a, r) => a + +r.n, 0);
  const approvedTotal = approved.reduce((a, r) => a + +r.total, 0);

  return (
    <div className="stack">
      <PageHead crumb="โครงการ" title="นำเข้าแผนที่อนุมัติแล้ว">
        <button className="btn" onClick={() => download('import/template', { format: 'xlsx' })}><Icon name="download" size={16} />ดาวน์โหลดแบบฟอร์ม (.xlsx)</button>
        <button className="btn" onClick={() => download('import/template', { format: 'csv' })}>แบบฟอร์ม .csv</button>
      </PageHead>

      {locked ? (
        <Alert tone="green" title={'แผนตั้งต้นปีงบประมาณ ' + meta.fiscal_year.year_be + ' ล็อกแล้ว'}>
          เมื่อ {thDate(locked, true)} — ยอดอนุมัติแก้ไขได้ผ่านคำขอปรับแผนเท่านั้น (BR-15) จึงนำเข้าเพิ่มไม่ได้
        </Alert>
      ) : (
        <div className="card card-b row wrap" style={{ gap: 12, justifyContent: 'space-between' }}>
          <div className="sm">
            โครงการสถานะ "อนุมัติในแผน" ขณะนี้ <b>{approvedCount}</b> โครงการ · ยอดขอรวม <b>{fmt(approvedTotal)}</b> บาท
            <div className="xs muted">นำเข้าได้หลายครั้งจนกว่าจะล็อกแผนตั้งต้น</div>
          </div>
          <button className="btn" disabled={busy || !approvedCount} onClick={() => setLockOpen(true)}><Icon name="lock" size={16} />ยืนยันและล็อกแผนตั้งต้น</button>
        </div>
      )}

      {!locked && (
        <div className="card">
          <div className="card-h"><h3>1. อัปโหลดไฟล์</h3><span className="xs muted">คอลัมน์: รหัส · ชื่อ · หน่วยงาน · แหล่งเงิน · หมวด · ยอดอนุมัติ · ไตรมาส</span></div>
          <div className="card-b stack" style={{ gap: 12 }}>
            <ul className="sm muted" style={{ margin: 0, paddingLeft: 20, lineHeight: 1.8 }}>
              <li><b>รหัส</b> เว้นว่างได้ ระบบออกรหัส P{String(meta.fiscal_year.year_be).slice(-2)}-xxx ให้ · หลายบรรทัดรหัสเดียวกัน = หนึ่งโครงการหลายรายการงบ</li>
              <li><b>หน่วยงาน / แหล่งเงิน / หมวด</b> ใช้ชื่อหรือรหัสในระบบ · แหล่งเงินต้องเป็นระดับย่อยสุด และหมวดต้องเป็นหมวดย่อย (BR-06)</li>
              <li><b>ไตรมาส</b> ใส่ 1–4 เช่น "2" หรือ "2,3"</li>
            </ul>
            <div className="row wrap">
              <input ref={fileRef} type="file" accept=".xlsx,.csv" aria-label="ไฟล์แผน" />
              <button className="btn primary" onClick={upload} disabled={busy}><Icon name="upload" size={16} />ตรวจสอบไฟล์</button>
            </div>
          </div>
        </div>
      )}

      {result && (
        <div className="card">
          <div className="card-h">
            <h3>2. ตรวจสอบ · {result.file}</h3>
            <span className="sm muted">{result.row_count} บรรทัด → {result.projects.length} โครงการ · รวม <b style={{ color: 'var(--text)' }}>{fmt(result.grand_total)}</b> บาท</span>
          </div>
          <div className="card-b stack" style={{ gap: 14 }}>
            {result.errors.length > 0 ? (
              <Alert tone="red" title={'พบข้อผิดพลาด ' + result.errors.length + ' รายการ'}>
                แก้ไขในไฟล์แล้วอัปโหลดใหม่
                <ul style={{ margin: '6px 0 0', paddingLeft: 18 }}>{result.errors.slice(0, 50).map((e, i) => <li key={i}>บรรทัด {e.row}: {e.message}</li>)}</ul>
                {result.errors.length > 50 && <div>… และอีก {result.errors.length - 50} รายการ</div>}
              </Alert>
            ) : <Alert tone="green">ข้อมูลถูกต้องทุกบรรทัด</Alert>}

            <div className="table-wrap"><table className="tbl">
              <thead><tr><th>แหล่งเงิน</th><th className="th-num">โครงการ</th><th className="th-num">ยอดจัดสรร</th><th className="th-num">คงเหลือในกอง (จริง)</th><th>ผลตรวจ BR-22</th></tr></thead>
              <tbody>{result.totals.map(t => (
                <tr key={t.fund_id}>
                  <td className="sm">{t.fund_name} <span className="xs mono muted">{t.fund_code}</span></td>
                  <td className="num">{t.projects}</td><td className="num">{fmt(t.amount)}</td><td className="num">{fmt(t.pool_actual)}</td>
                  <td>{t.short_by > 0 ? <Badge bg="var(--orange-bg)" fg="var(--orange-fg)">เงินไม่พอ ขาด {fmt(t.short_by)}</Badge> : <Badge bg="var(--green-bg)" fg="var(--green-fg)">ผ่าน</Badge>}</td>
                </tr>
              ))}</tbody>
            </table></div>

            <details>
              <summary className="sm" style={{ cursor: 'pointer', color: 'var(--link)' }}>ดูรายการโครงการ ({result.projects.length})</summary>
              <div className="table-wrap" style={{ marginTop: 8, maxHeight: 420, overflowY: 'auto' }}><table className="tbl dense">
                <thead><tr><th>รหัส</th><th>ชื่อ</th><th>หน่วยงาน</th><th>รายการงบ</th><th className="th-num">รวม</th><th>ไตรมาส</th></tr></thead>
                <tbody>{result.projects.map((p, i) => (
                  <tr key={i}><td className="mono xs">{p.code || '(อัตโนมัติ)'}</td><td className="sm">{p.title}</td><td className="sm">{p.unit_name}</td>
                    <td className="xs">{p.lines.map((l, j) => <div key={j}>{l.fund_code} · {l.category_name} · {fmt(l.amount)}</div>)}</td>
                    <td className="num sm">{fmt(p.total)}</td><td className="sm">{p.quarters.join(',') || '—'}</td></tr>
                ))}</tbody>
              </table></div>
            </details>

            {result.errors.length === 0 && (
              <Fragment>
                {needOverride && (
                  <div style={{ border: '1px solid var(--orange-line)', background: 'var(--orange-soft)', borderRadius: 8, padding: 14 }} className="stack">
                    <div className="row" style={{ gap: 10 }}>
                      <Badge bg="var(--orange)" fg="#fff">ยืนยันข้าม BR-22</Badge>
                      <span style={{ fontWeight: 600, color: 'var(--orange-fg)' }}>ยอดจัดสรรเกินเงินที่อยู่ในกองจริง — คงเหลือจัดสรรได้ (จริง) จะติดลบ</span>
                    </div>
                    <Field label="เหตุผล (บันทึกไว้ในทุกรายการจัดสรรและ audit log)" required>
                      <textarea className="textarea" rows={2} value={override} onChange={e => setOverride(e.target.value)} placeholder="เช่น นำเข้าแผนตั้งต้นตามมติ 2/2569 ก่อนได้รับเงินงวดแรก" />
                    </Field>
                  </div>
                )}
                <div className="row" style={{ justifyContent: 'flex-end' }}>
                  <button className="btn" onClick={() => setResult(null)}>ยกเลิก</button>
                  <button className="btn primary" disabled={busy || (needOverride && !override.trim())} onClick={commit}>
                    ยืนยันนำเข้า {result.projects.length} โครงการ
                  </button>
                </div>
              </Fragment>
            )}
          </div>
        </div>
      )}

      {created && (
        <Alert tone="green" title={'นำเข้า ' + created.length + ' โครงการแล้ว'} action={<a className="sm nowrap" style={{ color: 'inherit', fontWeight: 600 }} onClick={() => navigate('projects', { status: 'approved' })}>ดูรายการโครงการ</a>}>
          สร้างโครงการสถานะ "อนุมัติในแผน" และรายการจัดสรรในสมุดบัญชีครบทุกแหล่งเงิน
        </Alert>
      )}

      {lockOpen && (
        <Modal title="ยืนยันและล็อกแผนตั้งต้น" onClose={() => setLockOpen(false)} footer={<Fragment>
          <button className="btn" onClick={() => setLockOpen(false)}>ยกเลิก</button>
          <button className="btn primary" disabled={busy} onClick={lock}>ล็อกแผนตั้งต้น</button>
        </Fragment>}>
          <div className="sm">โครงการอนุมัติในแผน <b>{approvedCount}</b> โครงการ ยอดรวม <b>{fmt(approvedTotal)}</b> บาท</div>
          <Alert tone="orange">การล็อกยกเลิกไม่ได้ หลังล็อกแล้วการแก้ยอดอนุมัติทำได้ผ่านคำขอปรับแผนเท่านั้น (BR-15)</Alert>
        </Modal>
      )}
    </div>
  );
}

Object.assign(window, { ImportPage });
