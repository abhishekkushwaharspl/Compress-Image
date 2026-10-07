const dropZone = document.getElementById('dropZone');
const fileInput = document.getElementById('fileInput');
const fileList = document.getElementById('fileList');
const quality = document.getElementById('quality');
const qualityVal = document.getElementById('qualityVal');
const form = document.getElementById('compressForm');
const submitBtn = document.getElementById('submitBtn');

function fmt(bytes){
  if(bytes===0) return '0 B';
  const k=1024, u=['B','KB','MB','GB'];
  const i=Math.floor(Math.log(bytes)/Math.log(k));
  return (bytes/Math.pow(k,i)).toFixed(i===0?0:2)+' '+u[i];
}

function renderList(files){
  fileList.innerHTML='';
  if(!files.length) return;
  [...files].forEach(f=>{
    const div=document.createElement('div');
    div.className='file-item';
    const valid = f.type.startsWith('image/');
    div.innerHTML=`<span class="name">${f.name}</span><span class="size">${fmt(f.size)} ${valid?'':'⚠ not image'}</span>`;
    fileList.appendChild(div);
  });
}

if(quality && qualityVal){
  const upd=()=> qualityVal.textContent = quality.value+'%';
  quality.addEventListener('input', upd); upd();
}

if(fileInput){
  fileInput.addEventListener('change', ()=> renderList(fileInput.files));
}

if(dropZone){
  ['dragenter','dragover'].forEach(ev=>{
    dropZone.addEventListener(ev, e=>{e.preventDefault(); dropZone.classList.add('dragover');});
  });
  ['dragleave','drop'].forEach(ev=>{
    dropZone.addEventListener(ev, e=>{e.preventDefault(); dropZone.classList.remove('dragover');});
  });
  dropZone.addEventListener('drop', e=>{
    if(e.dataTransfer.files.length){
      fileInput.files = e.dataTransfer.files;
      renderList(fileInput.files);
    }
  });
}

if(form){
  form.addEventListener('submit', ()=>{
    if(submitBtn){
      submitBtn.textContent='Compressing…';
      submitBtn.disabled=true;
      submitBtn.style.opacity='.7';
      submitBtn.style.cursor='not-allowed';
    }
  });
}
