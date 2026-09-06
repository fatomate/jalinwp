const fs=require('node:fs');
const f=require('./design-editor-fixture.cjs');
const data=JSON.parse(fs.readFileSync(__dirname+'/design-blocks-fixtures.json','utf8'));
f.window.eval(fs.readFileSync(__dirname+'/../fames-mcp-gateway/assets/design-validation.js','utf8'));
const results=[];
function build(node) {
 const attrs={...node.attributes};
 for(const key of ['content','text','caption']) if(typeof attrs[key]==='string') attrs[key]=attrs[key].replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
 if(['core/image','core/cover'].includes(node.name) && attrs.id) attrs.url=data.media[attrs.id];
 if(node.name==='core/button' && attrs.linkTarget==='_blank') attrs.rel='noopener noreferrer';
 return f.wp.blocks.createBlock(node.name,attrs,node.innerBlocks.map(build));
}
function intended(expected,actual) {
 if(expected.length!==actual.length) return false;
 return expected.every((block,i)=>f.wp.blocks.validateBlock({...block,originalContent:actual[i].originalContent})[0] && intended(block.innerBlocks,actual[i].innerBlocks));
}
for(const fixture of data.fixtures){
    const report=f.window.FamesDesignValidation.validate(fixture.markup);
    results.push({name:fixture.name,pass:report.valid,report});
    const parsed=f.wp.blocks.parse(fixture.markup);
    results.push({name:fixture.name+' preserves intended attributes',pass:intended(fixture.blocks.map(build),parsed)});
    const roundtrip=f.wp.blocks.serialize(parsed);
    const reopened=f.window.FamesDesignValidation.validate(roundtrip);
    results.push({name:fixture.name+' editor save/reopen',pass:reopened.valid,report:reopened});
}
for(const [name,markup] of [['raw html','<script>alert(1)</script>'],['invalid saved heading','<!-- wp:heading -->\n<h1>Wrong markup</h1>\n<!-- /wp:heading -->'],['unknown block','<!-- wp:vendor/unknown --><div>Unknown</div><!-- /wp:vendor/unknown -->']]){
 const original=f.window.console.error; f.window.console.error=()=>{};
 const report=f.window.FamesDesignValidation.validate(markup); results.push({name:'Reject '+name,pass:!report.valid});
 f.window.console.error=original;
}
const canvasPath=__dirname+'/../fames-mcp-gateway/templates/canvas.html';
if(fs.existsSync(canvasPath)){
 const blocks=f.wp.blocks.parse(fs.readFileSync(canvasPath,'utf8'));
 const valid=blocks.every(b=>b.isValid && f.wp.blocks.validateBlock(b)[0]);
 results.push({name:'Block-theme canvas template',pass:valid});
}
const out={passed:results.filter(r=>r.pass).length,total:results.length,wordpress:'6.8.8',engine:'WordPress bundled JS via jsdom',cases:results};
fs.writeFileSync(__dirname+'/design-blocks-canonical-output.json',JSON.stringify(out,null,2));
console.log(JSON.stringify(out,null,2));
f.close(); process.exitCode=out.passed===out.total?0:1;
