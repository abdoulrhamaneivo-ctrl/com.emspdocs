
function initDashboardCounters(){
    function animCounter(el){
        const target=parseInt(el.dataset.value||'0',10);
        if (!Number.isFinite(target)) { return; }
        const dur=1200; const start=performance.now();
        function tick(now){
            const p=Math.min((now-start)/dur,1);
            const ease=1-Math.pow(1-p,3);
            el.textContent=Math.floor(target*ease).toLocaleString('fr-FR');
            if(p<1) requestAnimationFrame(tick);
        }
        tick(start);
    }
    document.querySelectorAll('.stat-value[data-value]').forEach(animCounter);

    document.querySelectorAll('.progress-bar[data-target]').forEach(function (bar) {
        requestAnimationFrame(function () {
            bar.style.width = (bar.dataset.target||0)+'%';
        });
    });
}
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initDashboardCounters);
} else {
    initDashboardCounters();
}

function sparkline(id, labels, data, color){
    const ctx=document.getElementById(id);
    if(!ctx||!window.Chart) return;
    new Chart(ctx,{
        type:'line',
        data:{
            labels:labels,
            datasets:[{
                data:data,
                borderColor:color,
                backgroundColor:'transparent',
                tension:0.4,
                pointRadius:0,
                borderWidth:2
            }]
        },
        options:{
            responsive:false,
            plugins:{legend:{display:false}},
            scales:{x:{display:false},y:{display:false}}
        }
    });
}
sparkline('spark1', [], [], '#1a3c6e');
sparkline('spark2', [], [], '#086136');
sparkline('spark3', [], [], '#e65100');
sparkline('spark4', [], [], '#0f766e');

(function(){
    const ctx=document.getElementById('chartLine'); if(!ctx||!window.Chart) return;
    const lbls = [];
    const up   = [];
    const ap   = [];
    new Chart(ctx,{
        type:'line',
        data:{labels:lbls, datasets:[
            {label:'Uploads', data:up, borderColor:'#1a3c6e', backgroundColor:'rgba(26,60,110,.16)', tension:.4, fill:true, pointRadius:2},
            {label:'ApprouvÃ©s', data:ap, borderColor:'#086136', backgroundColor:'rgba(8,97,54,.16)', tension:.4, fill:true, pointRadius:2},
        ]},
        options:{
            responsive:true,
            maintainAspectRatio:false,
            plugins:{legend:{labels:{color:'#334155'}}},
            scales:{
                x:{grid:{color:'rgba(15,23,42,.08)'}, ticks:{color:'#64748b'}},
                y:{grid:{color:'rgba(15,23,42,.08)'}, ticks:{color:'#64748b'}, beginAtZero:true}
            }
        }
    });
})();

(function(){
    const ctx=document.getElementById('chartDoughnut'); if(!ctx||!window.Chart) return;
    const data = [];
    const labels = [];
    const colors = labels.map(l=>({cours:'#1a3c6e',td:'#086136',correction:'#0f766e',examen:'#e65100',concours:'#c58900'}[l]||'#cbd5e1'));
    new Chart(ctx,{
        type:'doughnut',
        data:{labels:labels, datasets:[{data:data, backgroundColor:colors, borderWidth:1, borderColor:'rgba(255,255,255,.05)'}]},
        options:{
            responsive:true,
            maintainAspectRatio:false,
            cutout:'68%',
            plugins:{legend:{display:false}}
        }
    });
})();


