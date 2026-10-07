// assistant.jsx — floating AI assistant (chat panel, confirmation of actions, voice input) and its settings page

const AI_TOOL_LABELS = {
  get_dashboard: 'ดูภาพรวมหน้าหลัก', get_reference_data: 'ดูข้อมูลอ้างอิง', list_projects: 'ค้นหาโครงการ', get_project: 'ดูรายละเอียดโครงการ',
  get_fund_positions: 'ดูสถานะกองเงิน', list_revenue_estimates: 'ดูประมาณการรายรับ', list_ledger_entries: 'ค้นหาสมุดบัญชี',
  get_ledger_entry: 'ดูรายการบัญชี', list_users: 'ดูรายชื่อผู้ใช้', get_audit_log: 'ดูบันทึกการใช้งาน', open_page: 'เปิดหน้า',
  post_ledger_entry: 'บันทึกรายการกองเงิน', reverse_ledger_entry: 'กลับรายการบัญชี', save_revenue_estimate: 'บันทึกประมาณการรายรับ',
  delete_revenue_estimate: 'ลบประมาณการรายรับ',
};
const SPEECH_ERRORS = {
  'not-allowed': 'เบราว์เซอร์ไม่อนุญาตให้ใช้ไมโครโฟน', 'service-not-allowed': 'เบราว์เซอร์ไม่อนุญาตให้ใช้บริการแปลงเสียง',
  'no-speech': 'ไม่ได้ยินเสียง ลองพูดอีกครั้ง', 'audio-capture': 'ไม่พบไมโครโฟน', network: 'บริการแปลงเสียงของเบราว์เซอร์เชื่อมต่อไม่ได้',
  'language-not-supported': 'เบราว์เซอร์นี้ยังไม่รองรับการแปลงเสียงภาษาไทย',
};

// ------------------------------------------------------------------ speech-to-text (Web Speech API of the browser)
function useSpeech(onText) {
  const Rec = window.SpeechRecognition || window.webkitSpeechRecognition;
  const [listening, setListening] = useState(false);
  const [error, setError] = useState('');
  const rec = useRef(null);
  useEffect(() => () => { if (rec.current) rec.current.abort(); }, []);
  const start = base => {
    if (!Rec || rec.current) return;
    const r = new Rec();
    r.lang = 'th-TH';
    r.interimResults = true;
    r.continuous = false;
    let done = '';
    r.onresult = e => {
      let interim = '';
      for (let i = e.resultIndex; i < e.results.length; i++) {
        if (e.results[i].isFinal) done += e.results[i][0].transcript; else interim += e.results[i][0].transcript;
      }
      onText((base ? base.replace(/\s+$/, '') + ' ' : '') + (done + interim).trim());
    };
    r.onerror = e => { if (e.error !== 'aborted') setError(SPEECH_ERRORS[e.error] || 'แปลงเสียงไม่สำเร็จ (' + e.error + ')'); };
    r.onend = () => { rec.current = null; setListening(false); };
    try { r.start(); rec.current = r; setListening(true); setError(''); }
    catch (e) { setError('เริ่มรับเสียงไม่ได้'); }
  };
  const stop = () => { if (rec.current) rec.current.stop(); };
  return { supported: !!Rec, secure: window.isSecureContext !== false, listening, error, start, stop, clearError: () => setError('') };
}

// ------------------------------------------------------------------ light Markdown → React (no innerHTML)
function aiInline(text, key) {
  return String(text).split(/(\*\*[^*]+\*\*|`[^`]+`)/g).map((p, i) => {
    if (/^\*\*[^*]+\*\*$/.test(p)) return <b key={key + '-' + i}>{p.slice(2, -2)}</b>;
    if (/^`[^`]+`$/.test(p)) return <code key={key + '-' + i}>{p.slice(1, -1)}</code>;
    return p;
  });
}
function AiText({ text }) {
  const lines = String(text || '').replace(/\r/g, '').split('\n');
  const out = [];
  const cells = l => l.trim().replace(/^\|/, '').replace(/\|$/, '').split('|').map(c => c.trim());
  for (let i = 0; i < lines.length;) {
    const l = lines[i];
    if (/^\s*\|.*\|\s*$/.test(l)) {
      const rows = [];
      while (i < lines.length && /^\s*\|.*\|\s*$/.test(lines[i])) { rows.push(lines[i]); i++; }
      const body = rows.filter(r => !/^\s*\|?[\s:|-]+\|?\s*$/.test(r)).map(cells);
      const head = rows.length > 1 && /^\s*\|?[\s:|-]+\|?\s*$/.test(rows[1]) ? body.shift() : null;
      out.push(
        <div className="ai-table" key={'t' + i}><table>
          {head && <thead><tr>{head.map((c, j) => <th key={j}>{aiInline(c, 'h' + j)}</th>)}</tr></thead>}
          <tbody>{body.map((r, k) => <tr key={k}>{r.map((c, j) => <td key={j} className={/^[−-]?[\d,]+(\.\d+)?%?$/.test(c) ? 'num' : ''}>{aiInline(c, k + '-' + j)}</td>)}</tr>)}</tbody>
        </table></div>);
    } else if (/^\s*([-*•]|\d+[.)])\s+/.test(l)) {
      const ordered = /^\s*\d/.test(l);
      const items = [];
      while (i < lines.length && /^\s*([-*•]|\d+[.)])\s+/.test(lines[i])) { items.push(lines[i].replace(/^\s*([-*•]|\d+[.)])\s+/, '')); i++; }
      const Tag = ordered ? 'ol' : 'ul';
      out.push(<Tag key={'l' + i}>{items.map((t, j) => <li key={j}>{aiInline(t, 'li' + j)}</li>)}</Tag>);
    } else if (/^#{1,4}\s+/.test(l)) {
      out.push(<div className="ai-h" key={'h' + i}>{aiInline(l.replace(/^#+\s+/, ''), 'hd' + i)}</div>);
      i++;
    } else if (l.trim() === '') {
      i++;
    } else {
      const para = [];
      while (i < lines.length && lines[i].trim() !== '' && !/^\s*(\||[-*•]\s|\d+[.)]\s|#{1,4}\s)/.test(lines[i])) { para.push(lines[i]); i++; }
      out.push(<p key={'p' + i}>{para.map((t, j) => <Fragment key={j}>{j > 0 && <br />}{aiInline(t, 'p' + i + '-' + j)}</Fragment>)}</p>);
    }
  }
  return <div className="ai-text">{out}</div>;
}

// ------------------------------------------------------------------ floating widget
function aiSuggestions(perms) {
  const s = [];
  if (perms.view_funds) s.push('สรุปภาพรวมกองเงินปีนี้ให้หน่อย');
  if (perms.view_projects) s.push('โครงการไหนที่ยังไม่ได้ใช้เงินเลย');
  if (perms.view_ledger) s.push('รายการในสมุดบัญชี 10 รายการล่าสุด');
  if (perms.post_receipt) s.push('บันทึกรับเงินอุดหนุน 150,000 บาท');
  s.push('ฉันทำอะไรในระบบนี้ได้บ้าง');
  return s.slice(0, 4);
}

function AssistantWidget() {
  const { meta, refresh } = useApp();
  const info = meta.assistant || {};
  const storeKey = 'vecplan_ai_' + meta.user.id + '_' + meta.fy;
  const saved = useMemo(() => { try { return JSON.parse(sessionStorage.getItem(storeKey) || 'null') || {}; } catch (e) { return {}; } }, [storeKey]);
  const [open, setOpen] = useState(false);
  const [msgs, setMsgs] = useState(saved.msgs || []);
  const [pending, setPending] = useState(saved.pending || null);
  const [input, setInput] = useState('');
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState('');
  const bodyRef = useRef(null);
  const inputRef = useRef(null);
  const speech = useSpeech(setInput);

  useEffect(() => { setMsgs(saved.msgs || []); setPending(saved.pending || null); }, [storeKey]);
  useEffect(() => {
    try { sessionStorage.setItem(storeKey, JSON.stringify({ msgs: msgs.slice(-60), pending })); } catch (e) { /* storage full or blocked */ }
  }, [msgs, pending, storeKey]);
  useEffect(() => { if (bodyRef.current) bodyRef.current.scrollTop = bodyRef.current.scrollHeight; }, [msgs, pending, busy, open]);
  useEffect(() => {
    if (!open) return;
    const k = e => { if (e.key === 'Escape') setOpen(false); };
    window.addEventListener('keydown', k);
    if (inputRef.current) inputRef.current.focus();
    return () => window.removeEventListener('keydown', k);
  }, [open]);

  if (!info.enabled) return null;

  const call = async body => {
    setBusy(true);
    setErr('');
    try {
      const route = parseHash();
      const r = await post('assistant/chat', { ...body, context: { page: route.page, params: route.params } });
      setMsgs(r.messages);
      setPending(r.pending && r.pending.length ? r.pending : null);
      (r.actions || []).forEach(a => { if (a.type === 'navigate') navigate(a.page, a.params); });
      if (r.changed) refresh();
    } catch (e) {
      setErr(e.message);
    } finally {
      setBusy(false);
    }
  };
  const send = text => {
    const t = String(text || '').trim();
    if (!t || busy) return;
    if (speech.listening) speech.stop();
    const next = [...msgs, { role: 'user', content: t }];
    setMsgs(next);
    setInput('');
    setPending(null);
    call({ messages: next });
  };
  const decide = ok => {
    if (!pending || busy) return;
    const decisions = {};
    pending.forEach(p => { decisions[p.id] = ok; });
    setPending(null);
    call({ messages: msgs, decisions });
  };
  const retry = () => { if (msgs.length) call({ messages: msgs }); };
  const reset = () => { if (speech.listening) speech.stop(); setMsgs([]); setPending(null); setErr(''); setInput(''); };
  const onKey = e => {
    if (e.key === 'Enter' && !e.shiftKey && !e.nativeEvent.isComposing) { e.preventDefault(); send(input); }
  };
  const lastIsUser = msgs.length > 0 && msgs[msgs.length - 1].role === 'user';
  const rows = Math.min(5, Math.max(1, input.split('\n').length));

  return (
    <Fragment>
      {!open && (
        <button className="ai-fab" onClick={() => setOpen(true)} title="ผู้ช่วย AI" aria-label="เปิดผู้ช่วย AI">
          <Icon name="sparkle" size={24} stroke={1.8} />
          {pending && <span className="ai-fab-dot" />}
        </button>
      )}
      {open && (
        <section className="ai-panel" role="dialog" aria-label="ผู้ช่วย AI">
          <header className="ai-head">
            <div className="ai-avatar"><Icon name="sparkle" size={18} /></div>
            <div className="grow" style={{ minWidth: 0 }}>
              <div className="ai-title">ผู้ช่วย AI</div>
              <div className="xs muted ai-model" title={info.model}>{info.model}</div>
            </div>
            <button className="icon-btn plain" onClick={reset} title="เริ่มบทสนทนาใหม่" aria-label="เริ่มบทสนทนาใหม่" disabled={busy}><Icon name="newChat" size={17} /></button>
            <button className="icon-btn plain" onClick={() => setOpen(false)} title="ปิด (Esc)" aria-label="ปิด"><Icon name="x" size={17} /></button>
          </header>

          <div className="ai-body" ref={bodyRef} aria-live="polite">
            {msgs.length === 0 && (
              <div className="ai-welcome">
                <div className="ai-welcome-ic"><Icon name="sparkle" size={26} /></div>
                <div style={{ fontWeight: 600, color: 'var(--heading)' }}>สวัสดีครับ คุณ{meta.user.name.split(/\s+/)[0]}</div>
                <div className="sm muted">ถามข้อมูลแผนงาน โครงการ และกองเงินได้เลย หรือสั่งให้ช่วยบันทึกข้อมูลตามสิทธิ์ของคุณ — ทุกการบันทึกจะให้คุณยืนยันก่อนเสมอ</div>
                <div className="ai-suggest">
                  {aiSuggestions(meta.permissions).map(s => <button key={s} className="ai-chip-btn" onClick={() => send(s)}>{s}</button>)}
                </div>
              </div>
            )}
            {msgs.map((m, i) => {
              if (m.role === 'user') return <div key={i} className="ai-msg user">{m.content}</div>;
              if (m.role !== 'assistant') return null;
              return (
                <Fragment key={i}>
                  {m.tool_calls && m.tool_calls.length > 0 && (
                    <div className="ai-steps">{m.tool_calls.map(tc => (
                      <span key={tc.id} className="ai-step"><Icon name="check" size={12} />{AI_TOOL_LABELS[tc.function.name] || tc.function.name}</span>
                    ))}</div>
                  )}
                  {m.content && <div className="ai-msg bot"><AiText text={m.content} /></div>}
                </Fragment>
              );
            })}
            {pending && (
              <div className="ai-confirm">
                <div className="row" style={{ gap: 8, fontWeight: 600, color: 'var(--orange-fg)' }}><Icon name="warn" size={17} />ยืนยันก่อนดำเนินการ</div>
                {pending.map(p => (
                  <div key={p.id} className="ai-confirm-item">
                    <div style={{ fontWeight: 600, color: 'var(--heading)' }}>{p.title}</div>
                    <table><tbody>{p.fields.map(([k, v], j) => <tr key={j}><th>{k}</th><td>{v}</td></tr>)}</tbody></table>
                  </div>
                ))}
                <div className="row" style={{ justifyContent: 'flex-end', gap: 8 }}>
                  <button className="btn sm" onClick={() => decide(false)} disabled={busy}>ไม่อนุญาต</button>
                  <button className="btn sm primary" onClick={() => decide(true)} disabled={busy}>ยืนยันดำเนินการ</button>
                </div>
              </div>
            )}
            {busy && <div className="ai-msg bot ai-typing" aria-label="กำลังคิด"><span /><span /><span /></div>}
            {err && (
              <div className="ai-error">
                <div>{err}</div>
                {lastIsUser && <button className="btn sm" onClick={retry} disabled={busy}>ลองอีกครั้ง</button>}
              </div>
            )}
          </div>

          <footer className="ai-foot">
            {speech.error && <div className="ai-hint err" onClick={speech.clearError}>{speech.error}</div>}
            {speech.listening && <div className="ai-hint"><span className="ai-rec" />กำลังฟัง… พูดได้เลย</div>}
            <div className="ai-input">
              <textarea ref={inputRef} rows={rows} value={input} onChange={e => setInput(e.target.value)} onKeyDown={onKey}
                placeholder={speech.listening ? 'กำลังฟัง…' : 'พิมพ์คำถาม หรือกดไมค์เพื่อพูด'} aria-label="ข้อความถึงผู้ช่วย AI" maxLength={8000} />
              {speech.supported && (
                <button className={'icon-btn plain ai-mic' + (speech.listening ? ' on' : '')} disabled={!speech.secure}
                  onClick={() => speech.listening ? speech.stop() : speech.start(input)}
                  title={!speech.secure ? 'การพูดต้องเปิดระบบผ่าน HTTPS หรือ localhost' : speech.listening ? 'หยุดฟัง' : 'พูดแทนการพิมพ์'}
                  aria-label={speech.listening ? 'หยุดฟัง' : 'พูดแทนการพิมพ์'}>
                  <Icon name={speech.listening ? 'stop' : 'mic'} size={18} />
                </button>
              )}
              <button className="icon-btn ai-send" onClick={() => send(input)} disabled={busy || !input.trim()} title="ส่ง (Enter)" aria-label="ส่ง">
                <Icon name="send" size={17} />
              </button>
            </div>
            <div className="xs faint" style={{ textAlign: 'center' }}>AI อาจตอบผิดพลาดได้ ตรวจสอบตัวเลขสำคัญก่อนใช้งาน</div>
          </footer>
        </section>
      )}
    </Fragment>
  );
}

// ------------------------------------------------------------------ settings page (admin)
function AssistantSettingsPage() {
  const { meta, toast, reloadMeta } = useApp();
  const { data, error, loading, reload } = useApi('assistant/config');
  const [f, setF] = useState(null);
  const [models, setModels] = useState([]);
  const [test, setTest] = useState(null);
  const [busy, run] = useBusy();
  useEffect(() => {
    if (data) setF({ enabled: data.enabled, provider: data.provider, base_url: data.base_url, model: data.model, api_key: '', clear_key: false,
      temperature: data.temperature, allow_write: data.allow_write, instructions: data.instructions });
  }, [data]);
  if (!meta.permissions.ai_config) return <Forbidden />;
  if (error) return <LoadError error={error} onRetry={reload} />;
  if (loading || !data || !f) return <Skeleton rows={6} />;

  const P = data.providers;
  const set = (k, v) => setF(x => ({ ...x, [k]: v }));
  const setProvider = p => setF(x => {
    // Follow the preset URL unless the admin typed their own.
    const preset = Object.values(P).some(v => v.base_url && v.base_url === x.base_url) || !x.base_url;
    return { ...x, provider: p, base_url: preset ? P[p].base_url : x.base_url };
  });
  const urlChanged = f.base_url.replace(/\/+$/, '') !== data.base_url;
  const payload = () => ({ ...f, temperature: Number(f.temperature) });
  const save = () => run(async () => {
    try { await post('assistant/config_save', payload()); toast('บันทึกการตั้งค่าผู้ช่วย AI แล้ว', 'ok'); reload(); reloadMeta(); }
    catch (e) { toast(e.message, 'err'); }
  });
  const doTest = () => run(async () => {
    setTest(null);
    try { const r = await post('assistant/test', payload()); setTest({ ok: true, text: r.reply || '(ไม่มีข้อความ)', ms: r.ms }); }
    catch (e) { setTest({ ok: false, text: e.message }); }
  });
  const loadModels = () => run(async () => {
    try { const r = await post('assistant/models', payload()); setModels(r.models); toast('พบ ' + r.models.length + ' โมเดล', 'ok'); }
    catch (e) { toast(e.message, 'err'); }
  });
  const resetToSystem = () => run(async () => {
    if (!window.confirm('ลบการตั้งค่าของสถานศึกษานี้ และกลับไปใช้ค่าจากระบบกลาง?')) return;
    try { await post('assistant/config_reset'); toast('กลับไปใช้ค่าจากระบบกลางแล้ว', 'ok'); reload(); reloadMeta(); }
    catch (e) { toast(e.message, 'err'); }
  });

  return (
    <div className="stack">
      <PageHead crumb="ผู้ดูแลระบบ" title="ผู้ช่วย AI">
        {data.can_reset && <button className="btn" onClick={resetToSystem} disabled={busy}>ใช้ค่าจากระบบกลาง</button>}
        <button className="btn" onClick={doTest} disabled={busy || !f.model}>ทดสอบการเชื่อมต่อ</button>
        <button className="btn primary" onClick={save} disabled={busy}>บันทึก</button>
      </PageHead>
      {data.scope === 'system' && <Alert tone="blue">ค่านี้เป็นค่าเริ่มต้นของทุกสถานศึกษา — ผู้ดูแลระบบของแต่ละสถานศึกษากำหนดค่าของตนเองทับได้</Alert>}
      {data.inherited && <Alert tone="blue">สถานศึกษานี้ใช้การตั้งค่าจากระบบกลางอยู่ — เมื่อบันทึกหน้านี้จะเป็นการตั้งค่าเฉพาะของสถานศึกษา</Alert>}
      {!data.curl && <Alert tone="yellow">PHP ไม่ได้เปิด extension curl — ระบบจะเชื่อมต่อผ่าน stream แทน ถ้าเชื่อมต่อ HTTPS ไม่ได้ให้เปิด extension=curl ใน php.ini</Alert>}

      <div className="ai-settings-grid">
        <div className="card card-b stack">
          <label className="row" style={{ gap: 10, fontWeight: 600 }}>
            <input type="checkbox" checked={f.enabled} onChange={e => set('enabled', e.target.checked)} />
            เปิดใช้งานผู้ช่วย AI (แสดงปุ่มลอยมุมขวาล่างให้ผู้ใช้ทุกคน)
          </label>
          <Field label="ผู้ให้บริการ">
            <select className="select" value={f.provider} onChange={e => setProvider(e.target.value)}>
              {Object.entries(P).map(([k, v]) => <option key={k} value={k}>{v.label}</option>)}
            </select>
          </Field>
          <Field label="Base URL (OpenAI-compatible)" hint="ระบบจะเรียก {Base URL}/chat/completions และ {Base URL}/models">
            <input className="input mono" value={f.base_url} onChange={e => set('base_url', e.target.value)} placeholder="https://…/v1" />
          </Field>
          <Field label="API Key" hint={P[f.provider].needs_key ? 'จำเป็นสำหรับผู้ให้บริการนี้ · เก็บแบบเข้ารหัส และจะไม่แสดงกลับมาอีก' : 'เว้นว่างได้ถ้าเซิร์ฟเวอร์ไม่ต้องใช้คีย์'}>
            <input className="input mono" type="password" autoComplete="off" value={f.api_key} onChange={e => set('api_key', e.target.value)}
              placeholder={data.has_key && !f.clear_key && !urlChanged ? 'บันทึกไว้แล้ว ' + data.key_hint + ' — เว้นว่างเพื่อใช้คีย์เดิม' : 'วาง API key'} />
          </Field>
          {urlChanged && data.has_key && !f.api_key && <div className="xs" style={{ color: 'var(--orange-fg)', marginTop: -8 }}>เปลี่ยน URL แล้ว — คีย์เดิมจะไม่ถูกส่งไปยัง URL ใหม่ กรุณาใส่คีย์อีกครั้ง</div>}
          {data.has_key && !urlChanged && (
            <label className="row sm" style={{ gap: 8, marginTop: -6 }}>
              <input type="checkbox" checked={f.clear_key} onChange={e => set('clear_key', e.target.checked)} />ลบคีย์ที่บันทึกไว้
            </label>
          )}
          <Field label="โมเดล" hint={P[f.provider].model_hint + ' · ควรเลือกโมเดลที่รองรับ tool / function calling'}>
            <div className="row" style={{ gap: 8 }}>
              <input className="input mono grow" list="ai-models" value={f.model} onChange={e => set('model', e.target.value)} placeholder={P[f.provider].model_hint} />
              <button className="btn" onClick={loadModels} disabled={busy || !f.base_url}>ดึงรายชื่อโมเดล</button>
            </div>
            <datalist id="ai-models">{models.map(m => <option key={m} value={m} />)}</datalist>
          </Field>
          <Field label="Temperature" hint="0 = ตอบคงที่แม่นยำ · ค่าสูงขึ้น = หลากหลายขึ้น (แนะนำ 0.2–0.4)">
            <input className="input num" type="number" min="0" max="2" step="0.1" value={f.temperature} onChange={e => set('temperature', e.target.value)} style={{ width: 120 }} />
          </Field>
          <label className="row" style={{ gap: 10, alignItems: 'flex-start' }}>
            <input type="checkbox" checked={f.allow_write} onChange={e => set('allow_write', e.target.checked)} style={{ marginTop: 3 }} />
            <span>อนุญาตให้ผู้ช่วยบันทึก/แก้ไขข้อมูล<div className="xs muted">เช่น บันทึกรับเงิน กันเงิน กลับรายการ ประมาณการรายรับ — ทำได้เฉพาะเมื่อผู้ใช้คนนั้นมีสิทธิ์ และต้องกดยืนยันทุกครั้ง · ถ้าปิด ผู้ช่วยจะดูข้อมูลได้อย่างเดียว</div></span>
          </label>
          <Field label="คำแนะนำเพิ่มเติมสำหรับผู้ช่วย (ไม่บังคับ)" hint="เช่น ศัพท์เฉพาะของสถานศึกษา รูปแบบการตอบที่ต้องการ">
            <textarea className="textarea" rows={4} value={f.instructions} onChange={e => set('instructions', e.target.value)} maxLength={4000} />
          </Field>
          {test && <Alert tone={test.ok ? 'green' : 'red'} title={test.ok ? 'เชื่อมต่อสำเร็จ (' + (test.ms / 1000).toFixed(1) + ' วินาที)' : 'เชื่อมต่อไม่สำเร็จ'}>{test.text}</Alert>}
        </div>

        <div className="stack">
          <div className="card card-b stack" style={{ gap: 10 }}>
            <h3 className="h">ผู้ช่วยทำอะไรได้บ้าง</h3>
            <ul className="sm" style={{ margin: 0, paddingLeft: 18, lineHeight: 1.7 }}>
              <li>ตอบคำถามจากข้อมูลจริงในระบบ: ภาพรวม กองเงิน โครงการ สมุดบัญชี ประมาณการรายรับ</li>
              <li>ช่วยบันทึกรายการกองเงิน กลับรายการ และประมาณการรายรับ</li>
              <li>พาไปยังหน้าจอที่ต้องการ</li>
            </ul>
            <div className="sm muted">ผู้ช่วยเรียก API เดียวกับหน้าจอปกติในนามผู้ใช้ที่กำลังสนทนา จึงเห็นและทำได้เท่าที่บทบาทของผู้ใช้คนนั้นอนุญาต และทุกการบันทึกถูกเก็บใน audit log (ai.*)</div>
          </div>
          <div className="card card-b stack" style={{ gap: 10 }}>
            <h3 className="h">การพูดแทนการพิมพ์</h3>
            <div className="sm muted">ใช้ระบบแปลงเสียงเป็นข้อความของเบราว์เซอร์ (Web Speech API) ภาษาไทย รองรับ Chrome, Edge และ Safari · ต้องเปิดระบบผ่าน HTTPS หรือ localhost และอนุญาตไมโครโฟน · เบราว์เซอร์บางตัวส่งเสียงไปแปลงที่เซิร์ฟเวอร์ของผู้ผลิตเบราว์เซอร์</div>
          </div>
          <div className="card card-b stack" style={{ gap: 10 }}>
            <h3 className="h">ความเป็นส่วนตัว</h3>
            <div className="sm muted">คำถามและข้อมูลที่ผู้ช่วยค้นได้จะถูกส่งไปยังผู้ให้บริการ AI ที่ตั้งค่าไว้ หากต้องการให้ข้อมูลอยู่ภายในองค์กร ให้ใช้ LLM server ภายใน เช่น Ollama หรือ LM Studio</div>
          </div>
        </div>
      </div>
    </div>
  );
}

Object.assign(window, { AssistantWidget, AssistantSettingsPage, AiText, useSpeech });
