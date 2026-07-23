<style>
.approval-paper{background:#fff;border:2px solid #111;margin:0 auto 20px auto;padding:22px;font-family:'Malgun Gothic',sans-serif;color:#111}
.approval-paper table{width:100%;border-collapse:collapse}
.approval-paper th,.approval-paper td{border:1px solid #111;padding:4px 6px;font-size:13px;vertical-align:middle}
.proposal-paper{width:980px;min-height:1320px}
.leave-paper{width:840px;min-height:1160px}
.doc-title{text-align:center;font-size:42px;font-weight:700;letter-spacing:14px;padding:8px 0 14px;border-bottom:3px solid #111;margin-bottom:10px}
.doc-input{border:0;border-bottom:1px solid #999;background:transparent;width:100%;font-size:13px}
.doc-inline-input{display:inline-block;width:auto;min-width:220px}
.doc-money-input{width:140px;min-width:140px}
.doc-textarea{border:0;width:100%;min-height:66px}
.proposal-special-note-table{margin:10px 0}
.proposal-special-note-table th{width:12%;vertical-align:top;padding-top:12px}
.proposal-special-note-table td{height:156px;vertical-align:top;padding:6px}
.proposal-special-note-textarea{box-sizing:border-box;display:block;min-height:144px;padding:8px;line-height:1.6;resize:vertical}
.proposal-special-note-value{min-height:132px;white-space:pre-wrap;line-height:1.6}
.doc-sign{display:block;max-width:100%;max-height:42px;width:auto;height:auto;object-fit:contain;margin:0 auto}
.doc-time{font-size:11px;color:#444}
.doc-select{width:100%;border:1px solid #888;background:#fff;font-size:12px;padding:3px}
.doc-subline{font-size:13px;line-height:1.9}
.doc-attach{font-size:13px;margin-top:36px;padding:14px;border:2px solid #2563eb;background:#f8fbff}
.doc-attach-heading{display:flex;align-items:center;gap:10px;margin-bottom:10px;font-size:17px}
.doc-attach-guide{font-size:12px;font-weight:400;color:#475569}
.doc-attach-count{display:inline-block;padding:3px 9px;border-radius:999px;background:#2563eb;color:#fff;font-size:12px;font-weight:700}
.approval-line-table{table-layout:fixed}
.approval-line-table th,.approval-line-table td{text-align:center;vertical-align:middle}
.approval-line-table .approval-side-col{width:30px}
.approval-sign-row td{height:52px;max-height:52px;overflow:hidden;padding:2px 3px}
.approval-sign-cell{width:100%;height:48px;max-height:48px;overflow:hidden;display:flex;align-items:center;justify-content:center}
.approval-name-row td{font-weight:700}
.approval-time-row td{font-size:11px;color:#444}
.approval-delegated-cell{position:relative}
.approval-delegated-cell:after{content:"";position:absolute;left:10%;top:50%;width:80%;border-top:2px solid #111;transform:rotate(-35deg);opacity:.45;z-index:1}
.approval-delegated-cell .approval-sign-cell{position:relative;z-index:2}
.approval-delegated-status{font-weight:800;color:#111}
.approval-reference-box{margin:10px 0 14px 0;border:1px solid #d8dbe8;background:#f8fafc;padding:10px}
.approval-reference-title{font-weight:800;margin-bottom:6px}
.approval-reference-select{min-height:88px}
.approval-reference-help{font-size:12px;color:#475569;margin-top:6px}
.attach-row{display:flex;align-items:center;gap:10px;min-height:38px;margin-top:6px;padding:6px 9px;border:1px solid #d7deea;background:#fff}
.attach-row-present{border-color:#93c5fd;background:#eff6ff}
.attach-label{flex:0 0 112px;font-weight:700}
.attach-file-input{min-width:0;max-width:100%}
.attach-file-picker{display:flex;min-width:0;flex:1;flex-direction:column;gap:4px}
.attach-file-help{font-size:11px;color:#64748b}
.attach-file-list{display:flex;min-width:0;flex:1;flex-direction:column;gap:6px}
.attach-file-item{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.attach-file-name{min-width:0;flex:1;overflow-wrap:anywhere;font-weight:700;color:#111827}
.attach-empty{color:#94a3b8}
.approval-attachment-actions{display:inline-flex;align-items:center;gap:6px;flex-wrap:wrap}
.approval-attachment-drive-badge{display:inline-block;padding:3px 7px;border-radius:999px;background:#e8f0fe;color:#174ea6;font-size:11px;font-weight:700}
.approval-attachment-action{display:inline-block;padding:5px 10px;border-radius:4px;text-decoration:none!important;font-size:12px;font-weight:700}
.approval-attachment-view{background:#2563eb;color:#fff!important}
.approval-attachment-download{border:1px solid #2563eb;background:#fff;color:#1d4ed8!important}
.leave-approval-wrap{display:flex;justify-content:flex-end;margin-bottom:12px}
.leave-request-date-big{text-align:center;font-size:40px;font-weight:800;line-height:1.2;margin:34px 0 22px 0}
.leave-applicant-line{text-align:right;margin-top:28px;font-size:22px;font-weight:700}
.leave-applicant-name{display:inline-block;min-width:120px;text-align:center;font-size:28px;font-weight:900}
.leave-applicant-name-input{font-size:28px !important;font-weight:900;min-width:140px !important;width:140px !important;text-align:center}
.leave-sign-wrap{display:inline-block;position:relative;width:150px;height:52px;vertical-align:middle;text-align:center}
.leave-sign-text{position:absolute;left:0;right:0;bottom:4px;font-size:18px;color:#111;z-index:1}
.leave-sign-overlay{position:absolute;left:50%;top:50%;max-width:115px;max-height:46px;transform:translate(-50%,-50%);opacity:.92;z-index:2}
.leave-sign-empty{position:absolute;left:0;right:0;top:2px;font-size:11px;z-index:3}
@media print{.no-print,.approval-attachment-actions{display:none!important}body{margin:0;background:#fff}.approval-paper{box-shadow:none;margin:0 auto;border-color:#111}.doc-attach{border-color:#111;background:#fff}.attach-row,.attach-row-present{border-color:#bbb;background:#fff}}
</style>
