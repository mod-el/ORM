<?php
$elements_tree = $this->model->_ORM->getElementsTree();

$elements = array();
if($elements_tree){
	foreach($elements_tree['elements'] as $k_el=>$el){
		$collegamenti = array();
		foreach($el['children'] as $k=>$ch){
			if(!isset($ch['element']) or !$ch['element'] or $ch['element']=='Element') continue;
			$ch = array('element'=>$ch['element'], 'field'=>$ch['field'], 'descending'=>true);
			$collegamenti[$k] = $ch;
		}
		if($el['parent'] and $el['parent']['element']){
			$collegamenti['zkparent'] = $el['parent'];
			$collegamenti['zkparent']['descending'] = false;
		}

		unset($el['children']); unset($el['parent']);
		$el['collegamenti'] = $collegamenti;
		$el['name'] = $k_el;

		$elements[$k_el] = $el;
	}
}

/* Nel seguente blocco specchio i collegamenti non corrisposti (se una relazione è definita solo in un element ma nell'altro) */
foreach($elements as $k=>$el){
	foreach($el['collegamenti'] as $k_el=>$ch){
		if(!isset($elements[$ch['element']]))
			continue;
		$found = false;
		foreach($elements[$ch['element']]['collegamenti'] as $ch_ch){
			if($ch_ch['element']==$k){
				$found = true;
				break;
			}
		}
		if(!$found){
			$elements[$ch['element']]['collegamenti'][] = [
				'element'=>$el['name'],
				'field'=>false,
				'descending'=>!$ch['descending'],
			];
		}
	}
}
foreach($elements as $k=>$el)
	$elements[$k]['tot_collegamenti'] = count($el['collegamenti']);

uasort($elements, function($a, $b){
	if($a['tot_collegamenti']==$b['tot_collegamenti']){
		$nome = strcasecmp($a['name'], $b['name']);
		if($nome===0) return 0;
		return $nome>0 ? 1 : -1;
	}
	if($a['tot_collegamenti']==0) return 1;
	if($b['tot_collegamenti']==0) return -1;
	return $a['tot_collegamenti']>$b['tot_collegamenti'] ? 1 : -1;
});
?>
<style type="text/css">
#cont-canvas{
	position: relative;
	overflow-x: auto;
}

.element{
	background: #FFF;
	padding: 13px;
	font-size: 14px;
	border: solid #777 1px;
	cursor: default;
	display: inline-block;
	position: absolute;
	box-sizing: border-box;
	white-space: nowrap;
}

.el-data{
	font-size: 11px;
}

.element:hover{
	background: #EFF;
}

.label{
    width: 50%;
    text-align: right;
    padding-right: 10px;
    padding-bottom: 5px;
    padding-top: 5px;
}

.field{
    text-align: left;
    padding-bottom: 5px;
    padding-top: 5px;
}

input, textarea{
    width: 100%;
    -webkit-box-sizing: border-box;
    -moz-box-sizing: border-box;
    box-sizing: border-box;
}

textarea{
    height: 50px;
}

td{
    padding: 5px;
    vertical-align: top;
}
</style>

<script type="text/javascript">
var canvas, ctx;
var elements = <?=json_encode($elements)?>;
var margineX = 20; var margineY = 20;
var righe = {}; var colonne = {};
var posti = {}; var punteggi = {};

window.onload = function(){
	canvas = document.getElementById('canvas');
	ctx = canvas.getContext('2d');

	for(var i in elements){
		if(!aggiungiElemento(i, elements[i]))
			break;
	}

	var emergency_stop = 0; var ultimaSost = false;
	do{
		emergency_stop++;
		var best = {'posto1':false, 'posto2':false, 'score':false};
		for(var cr in posti){
			for(var cc in posti[cr]){
				var score = valutaSostituzione(elements[posti[cr][cc]], {'r':cr, 'c':cc}, punteggi[cr][cc]);
				if(score.score>0 && (best.score===false || score.score>best.score)){
					best = {'posto1':{'r':cr, 'c':cc}, 'posto2':{'r':score.r, 'c':score.c}, 'score':score.score};
				}
			}
		}

		if(best.score!==false){
			var el1 = posti[best.posto1.r][best.posto1.c];
			if(typeof posti[best.posto2.r]!='undefined' && typeof posti[best.posto2.r][best.posto2.c]!='undefined' && posti[best.posto2.r][best.posto2.c]){
				var el2 = posti[best.posto2.r][best.posto2.c];
				if(el1>el2) var k = el2+'-'+el1;
				else var k = el1+'-'+el2;

				if(ultimaSost!==false && ultimaSost.k==k && ultimaSost.score>=best.score) // Loop
					break;

				ultimaSost = {'k':k, 'score':best.score};
			}

			switchaElementi(best.posto1, best.posto2);
			//console.log('Guadagno di '+best.score);
			ricalcolaTuttiPunteggi();
		}

		//disegnaLinee();

		//if(!confirm('Proseguo?')) break;
	}while(best.score!==false && best.score>0 && emergency_stop<100);

	disegnaLinee();
};

function aggiungiElemento(name, element){
	var div = document.createElement('div');
	div.id = 'el-'+name;
	div.className = 'element';
	div.innerHTML = '<div>'+name+'</div><div class="el-data">Table: <i>'+element.table+'</i></div>';
	div = document.getElementById('temp-cont').appendChild(div);
	var w = div.offsetWidth+margineX; var h = div.offsetHeight+margineY;

	var posto = trovaPosto(element);
	if(posto===false)
		return false;

	if(posto.r==0){
		traslaTutto('r', h);
		posto.r = 1;
	}
	if(posto.c==0){
		traslaTutto('c', w);
		posto.c = 1;
	}

	if(typeof posti[posto.r]=='undefined'){
		posti[posto.r] = {};
		punteggi[posto.r] = {};
	}

	var sostituzione = valutaSostituzione(element, posto, posto.score);

	if(sostituzione.score>0){
		if(sostituzione.element){
			var div_sost = document.getElementById('el-'+sostituzione.element);
			posti[posto.r][posto.c] = posti[sostituzione.r][sostituzione.c];
			div_sost.dataset.r = posto.r;
			div_sost.dataset.c = posto.c;
			adattaCella(posto, div_sost.offsetWidth+margineX, div_sost.offsetHeight+margineY);
			posizionaElemento(div_sost, posto);
		}

		posto = {'r':sostituzione.r, 'c':sostituzione.c, 'score':sostituzione.actualScore};
	}

	adattaCella(posto, w, h);
	posizionaElemento(div, posto);

	div.dataset.r = posto.r;
	div.dataset.c = posto.c;
	document.getElementById('cont-canvas').appendChild(div);

	if(typeof posti[posto.r]=='undefined'){
		posti[posto.r] = {};
		punteggi[posto.r] = {};
	}
	posti[posto.r][posto.c] = name;
	punteggi[posto.r][posto.c] = posto.score;

	elements[name]['w'] = w-margineX;
	elements[name]['h'] = h-margineY;

	var totRighe = getTotPixel('righe')+10; var totColonne = getTotPixel('colonne')+10;
	if(totRighe>canvas.height)
		canvas.height = totRighe;
	if(totColonne>canvas.width)
		canvas.width = totColonne;

	if(sostituzione.score>0){ // C'è stata una sostituzione, i punteggi vanno ricalcolati
		ricalcolaTuttiPunteggi();
	}

	//if(!confirm('allora')) return false;

	return true;
}

function adattaCella(posto, w, h){
	if(typeof righe[posto.r]=='undefined'){
		righe[posto.r] = h;
	}else{
		if(righe[posto.r]<h){
			spostaTutto('r', parseInt(posto.r)+1, h-righe[posto.r]);
			righe[posto.r] = h;
		}
	}

	if(typeof colonne[posto.c]=='undefined'){
		colonne[posto.c] = w;
	}else{
		if(colonne[posto.c]<w){
			spostaTutto('c', parseInt(posto.c)+1, w-colonne[posto.c]);
			colonne[posto.c] = w;
		}
	}
}

function posizionaElemento(div, posto){
	var coords = calcolaCoords(posto);
	div.style.top = coords.y+'px';
	div.style.left = coords.x+'px';
}

function ricalcolaTuttiPunteggi(){
	for(var cr in posti){
		for(var cc in posti[cr]){
			punteggi[cr][cc] = getScore(elements[posti[cr][cc]], {'r':cr, 'c':cc});
		}
	}
}

function getTotPixel(type){
	var arr = window[type];
	var tot = 0;
	for(var i in arr)
		tot += arr[i];
	return tot;
}

function switchaElementi(posto1, posto2){
	var el1 = posti[posto1.r][posto1.c];
	if(typeof posti[posto2.r]!='undefind' && typeof posti[posto2.r][posto2.c]!='undefined' && posti[posto2.r][posto2.c])
		var el2 = posti[posto2.r][posto2.c];
	else
		var el2 = false;
	//console.log('Sostituisco '+el1+' con '+el2);

	var div1 = document.getElementById('el-'+el1); var div2 = document.getElementById('el-'+el2);

	var newPosti = JSON.parse(JSON.stringify(posti));
	if(el2)
		newPosti[posto1.r][posto1.c] = posti[posto2.r][posto2.c];
	newPosti[posto2.r][posto2.c] = posti[posto1.r][posto1.c];
	posti = newPosti;

	div1.dataset.r = posto2.r; div1.dataset.c = posto2.c;
	if(el2){
		div2.dataset.r = posto1.r;
		div2.dataset.c = posto1.c;
	}

	if(el2)
		adattaCella(posto1, div2.offsetWidth+margineX, div2.offsetHeight+margineY);
	adattaCella(posto2, div1.offsetWidth+margineX, div1.offsetHeight+margineY);
	posizionaElemento(div1, posto2);
	if(el2)
		posizionaElemento(div2, posto1);

	var totRighe = getTotPixel('righe')+10; var totColonne = getTotPixel('colonne')+10;
	if(totRighe>canvas.height)
		canvas.height = totRighe;
	if(totColonne>canvas.width)
		canvas.width = totColonne;
}

function trovaCollegamenti(element){
	var arr = {};
	for(var i in element.collegamenti){
		var coll = element.collegamenti[i];
		for(var cr in posti){
			for(var cc in posti[cr]){
				if(coll.element==element.name) continue; // Relazione ricursiva

				if(coll.element==posti[cr][cc]){
					var pixels = calcolaCoords({'r':cr, 'c':cc});
					pixels.x += parseInt(elements[coll.element].w/2);
					pixels.y += parseInt(elements[coll.element].h/2);

					if(i=='zkparent') var dir = 'in';
					else var dir = 'out';

					if(coll.descending){
						var str1 = element.name;
						var str2 = coll.element;
					}else{
						var str1 = coll.element;
						var str2 = element.name;
					}
					var k = str1+'-'+str2;

					arr[k] = {
						'element': coll.element,
						'r': cr,
						'c': cc,
						'x': pixels.x,
						'y': pixels.y,
						'dir': dir,
						'descending': coll.descending
					};
				}
			}
		}
	}
	return arr;
}

function nRighe(){
	var c = 0;
	for(var i in righe) c++;
	return c;
}

function nColonne(){
	var c = 0;
	for(var i in colonne) c++;
	return c;
}

function getMediaColonne(){
	var tot = 0;
	for(var i in colonne)
		tot += colonne[i];
	return tot/nColonne();
}

function getMediaRighe(){
	var tot = 0;
	for(var i in righe)
		tot += righe[i];
	return tot/nRighe();
}

function trovaPosto(element){
	var best = {'r':false, 'c':false, 'score':false};
	var score;

	var n_colonne = nColonne();
	if(getTotPixel('colonne')>=800){
		var min_c = 1;
		var max_c = n_colonne;
	}else{
		var min_c = 0;
		var max_c = n_colonne+1;
	}

	for(cr=0;cr<=nRighe()+1;cr++){
		for(cc=min_c;cc<=max_c;cc++){
			if(typeof posti[cr]!='undefined' && typeof posti[cr][cc]!='undefined' && posti[cr][cc]) // Il posto è già occupato
				continue;

			score = getScore(element, {'r':cr, 'c':cc});
			if(best.score===false || score>best.score || (score==best.score && (best.r==0 || best.c==0))){
				best.r = cr;
				best.c = cc;
				best.score = score;
			}
		}
	}

	return best;
}

function valutaSostituzione(element, posto, elOldScore){ // Ritorna il miglior elemento già presente con cui (eventualmente) scambiare di posto
	var best = {'r':false, 'c':false, 'score':false, 'element':false, 'actualScore':false};
	var elNewScore, sostOldScore, sostNewScore, edited_score, newEl;

	for(var cr in righe){
		for(var cc in colonne){
			elNewScore = getScore(element, {'r':cr, 'c':cc});
			if(typeof posti[cr]!='undefined' && posti[cr][cc]!='undefined' && posti[cr][cc]){ // Posto già occupato?
				sostOldScore = punteggi[cr][cc];
				sostNewScore = getScore(elements[posti[cr][cc]], posto);
				edited_score = (elNewScore-elOldScore)+(sostNewScore-sostOldScore);
				newEl = posti[cr][cc];
			}else{
				edited_score = (elNewScore-elOldScore);
				newEl = false;
			}

			if(best.score===false || edited_score>best.score){
				best.r = cr;
				best.c = cc;
				best.score = edited_score;
				best.element = newEl;
				best.actualScore = elNewScore;
			}
		}
	}

	return best;
}

function getScore(element, posto){
	var score = 0;

	if(element.tot_collegamenti>0){
		var temp_score = 0;
		if(element.tot_collegamenti<=8)
			var soglia = 2;
		else if(element.tot_collegamenti<=21)
			var soglia = 3;
		else if(element.tot_collegamenti<=41)
			var soglia = 4;
		else
			var soglia = 5;

		var coll_presenti = 0;
		for(var ci in element.collegamenti){
			var coll = element.collegamenti[ci];
			var coll_posto = trovaCoords(coll.element, false);
			if(coll_posto.r && coll_posto.c){
				coll_presenti++;

				var distanza = calcolaDistanza(posto, coll_posto);
				if(distanza<soglia)
					temp_score++;
				else
					temp_score += soglia-distanza;
			}
		}
		if(coll_presenti>0){
			var max_score = 100+element.tot_collegamenti; // Avere molti collegamenti dà un piccolo vantaggio come priorità sugli altri elementi
			var score = (temp_score/coll_presenti)*max_score;
		}
	}

	var n_righe = nRighe(); var n_colonne = nColonne();
	if(posto.r==0 || posto.c==0 || posto.r>n_righe || posto.c>n_colonne){ // Se il nuovo posto andrà a creare una nuova riga, calcolo un malus per il punteggio (così che la crescita sia più omogenea)
		var nuove_righe = n_righe;
		var nuove_colonne = n_colonne;
		if(posto.r==0 || posto.r>n_righe) nuove_righe++;
		if(posto.c==0 || posto.c>n_colonne) nuove_colonne++;

		score -= getScoreMalus(nuove_righe, nuove_colonne);
	}

	return score;
}

function calcolaDistanza(posto1, posto2){
	return Math.sqrt(Math.pow(parseInt(posto1.r)-parseInt(posto2.r), 2)+Math.pow(parseInt(posto1.c)-parseInt(posto2.c), 2));
}

function getScoreMalus(n_righe, n_colonne){ // Il malus è dato dalla formula (dim maggiore/dim minore)-1; in questo modo la tendenza sarà di assumere una forma quadrata (con dimensioni uguali il malus è 0)
	/* Uniformo matematicamente i due numeri in base alla media di pixel di grandezza */
	var mediaRighe = getMediaRighe(); var mediaColonne = getMediaColonne();
	if(mediaRighe>mediaColonne){
		if(mediaColonne>0)
			n_righe *= mediaRighe/mediaColonne;
	}else{
		if(mediaRighe>0)
			n_colonne *= mediaColonne/mediaRighe;
	}

	return ((Math.max(n_righe, n_colonne)/Math.min(n_righe, n_colonne))-1)*1;
}

function spostaTutto(type, from, amount){
	if(amount<=0)
		return false;

	switch(type){
		case 'c': var arr = colonne; break;
		case 'r': var arr = righe; break;
	}

	for(var i in arr){
		if(parseInt(i)<parseInt(from)) continue;

		var elementi = document.querySelectorAll('div[data-'+type+'="'+i+'"]');
		for(var ie in elementi){
			var el = elementi[ie];
			if(typeof el!='object') continue;

			switch(type){
				case 'c':
					var x = parseInt(el.style.left);
					x += amount;
					el.style.left = x+'px';
				break;
				case 'r':
					var y = parseInt(el.style.top);
					y += amount;
					el.style.top = y+'px';
				break;
			}
		}
	}
}

function traslaTutto(type, amount){
	spostaTutto(type, 1, amount);

	switch(type){
		case 'r':
			var arr = window.righe;
		break;
		case 'c':
			var arr = window.colonne;
		break;
	}

	var newArr = {1:amount};
	for(var i in arr)
		newArr[parseInt(i)+1] = arr[i];

	var elementi = document.querySelectorAll('div[data-'+type+']');
	for(var i in elementi){
		var el = elementi[i];
		if(typeof el!='object') continue;

		el.setAttribute('data-'+type, parseInt(el.getAttribute('data-'+type))+1);
	}

	var newPosti = {}; var newPunteggi = {};
	switch(type){
		case 'r':
			window.righe = newArr;

			for(var i in window.posti){
				newPosti[parseInt(i)+1] = window.posti[i];
				newPunteggi[parseInt(i)+1] = window.punteggi[i];
			}
		break;
		case 'c':
			window.colonne = newArr;

			for(var cr in window.posti){
				newPosti[cr] = {};
				newPunteggi[cr] = {};
				for(var i in window.posti[cr]){
					newPosti[cr][parseInt(i)+1] = window.posti[cr][i];
					newPunteggi[cr][parseInt(i)+1] = window.punteggi[cr][i];
				}
			}
		break;
	}

	window.posti = newPosti;
	window.punteggi = newPunteggi;
}

function trovaCoords(element, calcolaPixel){
	if(typeof calcolaPixel=='undefined')
		calcolaPixel = true;

	var coords = {'r':false, 'c':false, 'x':false, 'y':false};
	for(var cr in posti){
		for(var cc in posti[cr]){
			if(element==posti[cr][cc]){
				if(calcolaPixel){
					var pixels = calcolaCoords({'r':cr, 'c':cc});
					pixels.x += parseInt(elements[element].w/2);
					pixels.y += parseInt(elements[element].h/2);
				}else{
					pixels = {'x':false, 'y':false};
				}

				coords = {
					'r': cr,
					'c': cc,
					'x': pixels.x,
					'y': pixels.y
				};
			}
		}
	}
	return coords;
}

function calcolaCoords(posto){
	var x = 0; var y = 0;
	for(cr=1;cr<posto.r;cr++){
		if(typeof righe[cr]!='undefined')
			y += righe[cr];
	}
	for(cc=1;cc<posto.c;cc++){
		if(typeof colonne[cc]!='undefined')
			x += colonne[cc];
	}
	return {'x':x, 'y':y};
}

function disegnaLinee(){
	var color = 'black';
	var collegamenti_fatti = [];

	ctx.clearRect(0, 0, canvas.width, canvas.height);

	ctx.beginPath();
	ctx.strokeStyle = color;

	for(var i in elements){
		var element = elements[i];
		var coords = trovaCoords(element.name);
		var collegamenti = trovaCollegamenti(element);

		for(var ci in collegamenti){
			if(in_array(ci, collegamenti_fatti)) continue;

			var coll = collegamenti[ci];
			ctx.moveTo(coords.x, coords.y);
			ctx.lineTo(coll.x, coll.y);

			var obj1 = {'x':coords.x, 'y':coords.y, 'element':element.name};
			var obj2 = {'x':coll.x, 'y':coll.y, 'element':coll.element};

			if(coll.descending){
				var posto1 = obj1; var posto2 = obj2;
			}else{
				var posto1 = obj2; var posto2 = obj1;
			}

			disegnaFreccia(posto1, posto2);

			collegamenti_fatti.push(ci);
		}
	}

	ctx.stroke();
}

function disegnaFreccia(da, a){
	// Prima o poi
}

function newElChangedTable(form){
	var tendina = form.querySelector('select');
	var opt = tendina.options[tendina.selectedIndex];

	var nome = form.querySelector('input[name="name"]');
	nome.setValue(opt.dataset.suggested);

	var controller = form.querySelector('input[name="controller"]');
	controller.setValue(opt.dataset.suggested);
}

function in_array(needle, haystack, argStrict){
	var key = '',
		strict = !! argStrict;

	if (strict) {
		for (key in haystack) {
			if (haystack[key] === needle) {
				return true;
			}
		}
	} else {
		for (key in haystack) {
			if (haystack[key] == needle) {
				return true;
			}
		}
	}

	return false;
}
</script>

<h2>ORM module configuration</h2>

<form action="" method="post">
    <h3>API permissions</h3>
    <table style="width: 100%">
        <tr style="color: #2693FF">
            <td>
                Delete?
            </td>
            <td>
                User Idx
            </td>
            <td>
                User Id
            </td>
            <td>
                Function
            </td>
            <td>
                Element
            </td>
            <td style="width: 300px">
                Permissions
            </td>
        </tr>
		<?php
		$permissions = $this->model->_Db->select_all('zk_orm_permissions');
		foreach($permissions as $r){
			?>
            <tr>
                <td>
                    <input type="checkbox" name="<?=$r['id']?>-delete" value="1" />
                </td>
                <td>
                    <input type="text" name="<?=$r['id']?>-user_idx" value="<?=entities($r['user_idx'])?>" />
                </td>
                <td>
                    <input type="text" name="<?=$r['id']?>-user_id" value="<?=entities($r['user_id'])?>" />
                </td>
                <td>
                    <input type="text" name="<?=$r['id']?>-function" value="<?=entities($r['function'])?>" />
                </td>
                <td>
                    <input type="text" name="<?=$r['id']?>-element" value="<?=entities($r['element'])?>" />
                </td>
                <td>
                    <textarea name="<?=$r['id']?>-permissions"><?=entities($r['permissions'])?></textarea>
                </td>
            </tr>
			<?php
		}
		?>
        <tr>
            <td>

            </td>
            <td>
                <input type="text" name="new-user_idx" />
            </td>
            <td>
                <input type="text" name="new-user_id" />
            </td>
            <td>
                <input type="text" name="new-function" />
            </td>
            <td>
                <input type="text" name="new-element" />
            </td>
            <td>
                <textarea name="new-permissions">{}</textarea>
            </td>
        </tr>
        <tr style="font-style: italic; font-size: 10px">
            <td>
                Examples:
            </td>
            <td>
                0<br />Admin
            </td>
            <td>
                1<br />2<br />3
            </td>
            <td>
                $el['editable']==true
            </td>
            <td>
                Client<br />Client:23
            </td>
            <td>
                {"*":true}<br />{"save":true, "delete":true}<br />{"save":["title", "description"]}
            </td>
        </tr>
    </table>

    <p>
        <input type="submit" value="Save" />
    </p>
</form>

<hr />

<h2>Elements Graph</h2>

<div id="temp-cont"></div>

<div id="cont-canvas">
	<canvas id="canvas"></canvas>
</div>