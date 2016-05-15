<?php
/*
 * Render scaled PDF using DOMPDF.
 * Options include: margin_{left, right, bottom, top} [int in pt], scale [boolean] (to scale contents to one single page),
 * papersize [string/array], orientation [string],
 * with [int] (a hint, can be ignored altogether, I'm not using this option anymore),
 * scale_with [int] (a with hint when scale is set to true, I'm not using this anymore, too),
 * php [string] (some php script to run as page_script), pagecount_footer [boolean] (this indicates if a footer
 * containing date and pagecount is wanted)
 * @param string $html HTML-Source to render
 * @param array $options Options controlling the rendering
 */
function render_scaled_pdf($html, $options=array())
{
	$versuche=4;
	$papersize = $options['papersize'];
	$orientation = $options['orientation'];
	debug_pdf_scaling("options: ".print_r(array('scale'=>$options['scale'], 'margin_left'=>$options['margin_left'], 'margin_right'=>$options['margin_right'], 'margin_top'=>$options['margin_top'], 'margin_bottom'=>$options['margin_bottom']), true).'<br>');
	debug_pdf_scaling("orientation: $orientation, papersize: $papersize<br>");
	//einmal ursprüngliche seitengröße berechnen
	$dompdf=new DOMPDF();
	$dompdf->load_html('<html><head></head><body>&nbsp;</body></html>');
	$dompdf->set_paper($papersize, $orientation);
	$dompdf->render();
	$pdf=$dompdf->get_canvas();
	$w=$pdf->get_width();
	$h=$pdf->get_height();
	debug_pdf_scaling("init: w: $w, h: $h<br>");
	$r=$h/$w;
	$versuch=1;
	$new_w=$w;
	//bei skalierung auf eine seite breitenhinweis forcieren
	//(große seite, die dann in einem einzelnen schritt verkleinert werden muss --> spart rendering schritte)
	if($options['scale'] && !($options['width']>0) && $options['scale_width']>0)
		$options['width']=$options['scale_width'];
	//schnellskalierung mit breitenhinweis...achtung: es wird höchstens noch weiter verkleinert, nicht aber wieder vergrößert
	if($options['width']>0)
	{
		$new_w=$options['width']/$w;
		if($new_w<1)
			$new_w=$w;
		else
			$new_w=$w*$new_w;
	}
	$new_h=$new_w*$r;
	//skalierungsschleife
	$maxmem=$mem=0;
	do {
		ob_start();
		unset($pdf);
		$dompdf->__destruct();
		unset($dompdf);
		ob_end_clean();
		$cm=memory_get_usage(true);
		$pm=memory_get_peak_usage(true);
		debug_pdf_scaling("<b>rendering versuch $versuch: $new_w, $new_h (current_memory: $cm, peak_memory: $pm)...</b><br>");
		if(ceil(($maxmem*$new_w)/1024)>(int)mb_substr(ini_get('memory_limit'), 0, -1))
		{
			error_log("ERROR: would now start offsite rendering $versuch: $new_w, $new_h...(".ceil(($maxmem*$new_w)/1024).'>'.mb_substr(ini_get('memory_limit'), 0, -1).")");
			return 'ERROR';
		}
		ob_start();
		$dompdf=new DOMPDF();
		$dompdf->load_html($html);
		if($orientation=='portrait')
			$dompdf->set_paper(array(0, 0, $new_w, $new_h), $orientation);
		else
			$dompdf->set_paper(array(0, 0, $new_h, $new_w), $orientation);
		$dompdf->render(array($options['margin_left'], $options['margin_right'], $options['margin_top'], $options['margin_bottom']));
		debug_pdf_scaling(ob_get_contents().'<br>');
		ob_end_clean();
		$mem=ceil((memory_get_usage(true)/$new_w)/1024);
		$maxmem=max($maxmem, $mem);
		debug_pdf_scaling("speicherverbrauch pro pixel: ".$mem.'KB (maxmem: '.$maxmem.'KB, limit: '.ini_get('memory_limit').')<br>');
		$pdf=$dompdf->get_canvas();
		$php='';
		if($options['pagecount_footer'])
			$php.=pagecount_footer_php();
		if(isset($options['php']))
			$php.=$options['php'];
		$pdf->page_script($php);
		list($scaleflag_left, $scaleflag_right)=$pdf->get_scaleflags();
		$linker_ueberstand=max(0, $options['margin_left']-$scaleflag_left);
		$rechter_ueberstand=max($scaleflag_right-($new_w-$options['margin_right']), 0);
		$_scaleflag=$scaleflag=$linker_ueberstand+$rechter_ueberstand;
		$pagecount=$pdf->get_page_count();
		$_yscaleflag=array_sum($yscaleflag=$pdf->get_yscaleflags())/*-(max($pagecount-1, 0)*($options['margin_top']))*/+$options['margin_bottom'];
		debug_pdf_scaling("margin_left: {$options['margin_left']}, margin_right: {$options['margin_right']}<br>");
		debug_pdf_scaling("get_scaleflag() --> left: $scaleflag_left (&uuml;berstand: ".$linker_ueberstand."), right: $scaleflag_right (&uuml;berstand: ".$rechter_ueberstand."), summe &uuml;berstand: $scaleflag<br>");
		debug_pdf_scaling("pages: $pagecount, margin_top: {$options['margin_top']}, margin_bottom: {$options['margin_bottom']}<br>");
		debug_pdf_scaling("get_yscaleflag() --> ".print_r($yscaleflag, true).", sum: $_yscaleflag ==> ".($_yscaleflag-$new_h)."<br>");
		if($scaleflag<8.0)
			$scaleflag=0;				//bei solch kleinem überstand kann das rendering abgebrochen werden
		if($scaleflag)					//rand überschritten?
		{
			$new_w=$new_w+$scaleflag;
			$new_h=$new_w*$r;
		}
		if($pagecount > 1 && $options['scale'])
		{
			$new_h=max($new_h, $_yscaleflag);
			$new_w=$new_h*(1/$r);
			$scaleflag=1;					//noch ein durchlauf
		}
		///This scales DOWN (makes small pages containing not much content appear bigger)
		/*if($options['scale'] && !$_scaleflag && $pagecount==1)// && ($new_h-$_yscaleflag)<-16)
		{
			$puffer_rechts=max(($new_w-$options['margin_right'])-$scaleflag_right, 0);
			$puffer_unten=max(($new_h-$options['margin_bottom'])-$_yscaleflag, 0);
			$sub_w=floor(min($puffer_rechts, $puffer_unten*(1/$r)));
			debug_pdf_scaling("puffer_rechts: $puffer_rechts, puffer_unten: $puffer_unten [".($puffer_unten*(1/$r))."], sub_w: $sub_w<br>");
			debug_pdf_scaling("new_w: $new_w - $sub_w = ".($new_w-$sub_w)."<br>");
			$new_w-=$sub_w;
			$new_h=$new_w*$r;
			$scaleflag=$sub_w;					//noch ein durchlauf, wenn vergrößert
			if($sub_w<32)
				$scaleflag=0;					//solch kleine veränderungen werden nicht mehr gerendert
			//$marker=array(0, 0, $scaleflag_right, $new_h, Array( 0 => 1, 1 => 0, 2 => 0, 3 => null, hex => '#ff0000', 'r' => 1, 'g' => 0, 'b' => 0));
			//$pdf->filled_rectangle($marker[0], $marker[1], $marker[2], $marker[3], $marker[4]);
			//$scaleflag=0;
		}*/
	} while($scaleflag && $versuch++<$versuche);
	debug_pdf_scaling("scaling done (".$scaleflag.")<br>");
	$cm=memory_get_usage(true);
	$pm=memory_get_peak_usage(true);
	debug_pdf_scaling("<b>current_memory: $cm, peak_memory: $pm...</b><br>");
	return $dompdf->output();				//zuletzt generierte pdf zurückgeben
}

function debug_pdf_scaling($text)
{
	//ignore debug output
}

function pagecount_footer_php()
{
	return '
	if(isset($pdf))
	{
		$font=Font_Metrics::get_font("verdana");
		// If verdana isnt available, well use sans-serif.
		if(!isset($font)) { Font_Metrics::get_font("sans-serif"); }
		$size=12;
		$color=array(0,0,0);
		
		//get sizes
		$text_height=Font_Metrics::get_font_height($font, $size);
		$w=$pdf->get_width();
		$h=$pdf->get_height();
		$y=$h-(2*$text_height)-24;
		list($left, $right, $top, $bottom)=$pdf->get_margins();
		
		$text="Seite $PAGE_NUM/$PAGE_COUNT";
		$width=Font_Metrics::get_text_width($text, $font, $size);
		$pdf->text($w-$right-$width, $y, $text, $font, $size, $color);
		$pdf->text($left, $y, date("d.m.y")."  ".date("H:i:s"), $font, $size, $color);
	}
	';
}
?>