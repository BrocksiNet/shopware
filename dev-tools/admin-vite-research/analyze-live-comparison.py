import json, re, statistics
from pathlib import Path
import matplotlib
matplotlib.use('Agg')
import matplotlib.pyplot as plt
root=Path(__file__).parent
result={}
for name in ['baseline','shared']:
    raw=json.loads((root/f'{name}-live-samples.json').read_text())
    samples=raw['samples']
    runs=[]
    for response in json.loads((root/f'browser-{name}.json').read_text())['runs']:
        value=json.loads(re.search(r'```json\n(.*?)\n```',response['content'][0]['text'],re.S)[1])
        start=value['timeOrigin']; end=start+value['readyMs']
        active=[s for s in samples if start<=raw['started']+s['ms']<=end]
        value['serverCpuSeconds']=active[-1]['cpuSeconds']-active[0]['cpuSeconds']
        value['peakRssGiB']=max(s['rssMiB'] for s in active)/1024
        value['peakPssGiB']=max(s['pssMiB'] for s in active)/1024
        runs.append(value)
    result[name]={'runs':runs,'listeners':sorted(set(s['listeners'] for s in samples)), 'nodeProcesses':sorted(set(len(s['processes']) for s in samples)), 'peakFds':max(s['fds'] for s in samples), 'inotifyWatches':sorted(set(s['inotifyWatches'] for s in samples))}
(root/'live-comparison-summary.json').write_text(json.dumps(result,indent=2)+'\n')
fig, axes=plt.subplots(3,3,figsize=(13,10))
colors=['#677485','#147D74']; names=['Separate ports','Shared listener']
charts=[('TCP listeners','listeners',1,False),('Peak summed RSS (GiB)','peakRssGiB',1,True),('Peak summed PSS (GiB)','peakPssGiB',1,True),('Dashboard ready (s)','readyMs',.001,True),('Completed requests before ready','completedRequestsStartedBeforeReady',1,True),('Server CPU to dashboard (s)','serverCpuSeconds',1,True),('Document TTFB (ms)','ttfbMs',1,True),('Resource TTFB p95 (ms)','resourceTtfbP95Ms',1,True),('Peak open file descriptors','peakFds',1,False)]
for ax,(title,key,scale,perrun) in zip(axes.flat,charts):
    for index,name in enumerate(['baseline','shared']):
        data=result[name]
        values=[r[key]*scale for r in data['runs']] if perrun else [data[key][0] if isinstance(data[key],list) else data[key]]
        middle=statistics.median(values)
        ax.bar(index,middle,color=colors[index],alpha=.75,width=.55)
        if perrun: ax.scatter([index-.08,index,index+.08],values,color=colors[index],edgecolors='white',s=38,zorder=3)
        ax.annotate(f'{middle:,.2f}' if middle<100 else f'{middle:,.0f}',(index,middle),xytext=(0,6),textcoords='offset points',ha='center',fontsize=10)
    ax.set_xticks([0,1],names,fontsize=9);ax.set_title(title,fontsize=11,pad=14)
    ax.set_ylim(0,ax.get_ylim()[1]*1.18); ax.spines[['top','right']].set_visible(False);ax.grid(axis='y',alpha=.15);ax.set_axisbelow(True)
fig.suptitle('Shopware Vite development: real shared-listener comparison',fontsize=17,y=.99)
fig.text(.5,.945,'77 extension entries: Commercial + 40 generated plugins | 3 reloads per topology | warmed server caches',ha='center',fontsize=11)
fig.text(.5,.02,'Dots: individual runs; bars: medians. Browser cache bypass requested; immutable dependencies still cached.\nSequential local measurements, no throttling or order randomization. These are observations, not release guarantees.',ha='center',fontsize=10)
fig.tight_layout(rect=(0,.06,1,.925),h_pad=2.3,w_pad=2)
fig.savefig(root/'live-comparison.png',dpi=160)
fig.savefig(root/'live-comparison.svg')
print(json.dumps({name:{'medianReadySeconds':statistics.median(r['readyMs'] for r in data['runs'])/1000,'medianServerCpuSeconds':statistics.median(r['serverCpuSeconds'] for r in data['runs']),'peakRssGiB':max(r['peakRssGiB'] for r in data['runs'])} for name,data in result.items()},indent=2))
