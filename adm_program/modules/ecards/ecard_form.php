<?php
/******************************************************************************
 * Grußkarte Form
 *
 * Copyright    : (c) 2004 - 2007 The Admidio Team
 * Homepage     : http://www.admidio.org
 * Module-Owner : Roland Eischer 
 * Based on     : Jochen Erkens: Photogalerien &
 *                Elmar Meuthen: E-Mails verschicken
 * License      : GNU Public License 2 http://www.gnu.org/licenses/gpl-2.0.html
 *
 * Uebergaben:
 *
 * pho_id:		id der Veranstaltung deren Bilder angezeigt werden sollen
 * photo:       Name des Bildes ohne(.jpg) später -> (admidio/adm_my_files/photos/<* Gallery *>/$_GET['photo'].jpg)
 * usr_id:		Die Benutzer id an dem die Grußkarte gesendet werden soll
 *
 *****************************************************************************/

require_once("../../system/photo_event_class.php");
require_once("../../system/common.php");
require_once("../photos/photo_function.php");
require_once("ecard_function.php");

// Variablen die später in die DB kommen und vom Admin änderbar sind
//**********************************************************
/**/	$max_w_card			= "400";		// Maximale Breite des Grußkarten Bildes							
/**/	$max_h_card			= "250";		// Maximale Höhe des Grußkarten Bildes	
/**/	$max_w_view			= "250";		// Maximale Breite des angezeigten Bildes							
/**/	$max_h_view 		= "250";		// Maximale Höhe des angezeigten Bildes
/*		es können hier mehere Templates eingetragen werden welche dann vom Benutzt ausgewählt werden dürfen				
/**/	$templates 			= array ("ecard_1.tpl","ecard_2.tpl","ecard_3.tpl"); 
/*      es können hier mehere Schrift name eingetragen werden welche dann vom Benutzer ausgewählt werden dürfen */
/**/	$fonts 				= array ("Comic Sans MS","Arial","Arial Black","Courier","Georgia","Helvetica","Impact","Script","Times Roman","Verdana"); 
/*      es können hier mehere Schriftgrößen eingetragen werden welche dann vom Benutzer ausgewählt werden dürfen */
/**/	$font_sizes 		= array ("14","9","10","11","12","13","14","15","16","17","18","20","22","24","30"); 
/*      es können hier mehere Schrift Farben eingetragen werden welche dann vom Benutzer ausgewählt werden dürfen */
/**/	$font_colors 		= array("black","yellow","orange","red","maroon","fuchsia","purple","lime","green","teal","aqua","blue","navy","silver","gray","olive"); 
/**/	$tmpl_folder 		= "../../layout/";								
/**/	$max_length 		= 150;  // Maximale Länge des Grußkarten Textes
/**/	$ecard_plain_data 	= "Du hast eine E-Card von einem Mitglied des Vereins ".$g_organization." erhalten.\n Falls du diese nicht sehen kannst befindet sich diese im Anhang der Mail";
/**/	$msg_error_1		= "Es ist ein Fehler bei der Verarbeitung der E-C@rd aufgetreten. Bitte probier es zu einem späteren Zeitpunkt noch einmal.";
/**/	$msg_error_2 		= "Es sind einige Eingabefelder nicht bzw. nicht richtig ausgefüllt. Bitte füll diese aus, bzw. korrigier diese.";
/**/	$g_preferences['enable_e@card_module']	= 1;
//**********************************************************

$error_msg			= "";

// pruefen ob das Modul ueberhaupt aktiviert ist
if ($g_preferences['enable_e@card_module'] != 1)
{
    // das Modul ist deaktiviert
    $g_message->show("module_disabled");
}
// pruefen ob User eingeloggt ist
if(!$g_valid_login)
{
 $g_message->show("invalid");
} 
//ID Pruefen
if(isset($_GET["pho_id"]) && is_numeric($_GET["pho_id"]))
{
    $pho_id = $_GET["pho_id"];
}
else 
{
    $pho_id = NULL;
}

unset($_SESSION['photo_event_request']);

//Wurde keine Veranstaltung uebergeben kann das Navigationsstack zurückgesetzt werden
if ($pho_id == NULL)
{
    $_SESSION['navigation']->clear();
}

//URL auf Navigationstack ablegen
$_SESSION['navigation']->addUrl(CURRENT_URL);

// Fotoveranstaltungs-Objekt erzeugen oder aus Session lesen
if(isset($_SESSION['photo_event']) && $_SESSION['photo_event']->getValue("pho_id") == $pho_id)
{
    $photo_event =& $_SESSION['photo_event'];
    $photo_event->db =& $g_db;
}
else
{
    // einlesen der Veranstaltung falls noch nicht in Session gespeichert
    $photo_event = new PhotoEvent($g_db);
    if($pho_id > 0)
    {
        $photo_event->getPhotoEvent($pho_id);
    }

    $_SESSION['photo_event'] =& $photo_event;
}

// pruefen, ob Veranstaltung zur aktuellen Organisation gehoert
if($pho_id > 0 && $photo_event->getValue("pho_org_shortname") != $g_organization)
{
    $g_message->show("invalid");
} 


if ($g_valid_login && !isValidEmailAddress($g_current_user->getValue("E-Mail")))
{
    // der eingeloggte Benutzer hat in seinem Profil keine gueltige Mailadresse hinterlegt,
    // die als Absender genutzt werden kann...
    $g_message->addVariableContent("$g_root_path/adm_program/modules/profile/profile.php", 1, false);
    $g_message->show("profile_mail");
}
if(!isset($_GET["photo"]))
{
    $g_message->show("invalid");
}

if (isset($_GET["usr_id"]))
{
    // Falls eine Usr_id uebergeben wurde, muss geprueft werden ob der User ueberhaupt
    // auf diese zugreifen darf oder ob die UsrId ueberhaupt eine gueltige Mailadresse hat...
    if (!$g_valid_login)
    {
        //in ausgeloggtem Zustand duerfen nie direkt usr_ids uebergeben werden...
        $g_message->show("invalid");
    }

    if (is_numeric($_GET["usr_id"]) == false)
    {
        $g_message->show("invalid");
    }

    //usr_id wurde uebergeben, dann Kontaktdaten des Users aus der DB fischen
    $user = new User($g_db, $_GET['usr_id']);

    // darf auf die User-Id zugegriffen werden    
    if((  $g_current_user->editUser() == false
       && isMember($user->getValue("usr_id")) == false)
    || strlen($user->getValue("usr_id")) == 0 )
    {
        $g_message->show("usrid_not_found");
    }

    // besitzt der User eine gueltige E-Mail-Adresse
    if (!isValidEmailAddress($user->getValue("E-Mail")))
    {
        $g_message->show("usrmail_not_found");
    }

    $user_email = $user->getValue("E-Mail");
	$user_name  = $user->getValue("Vorname")." ".$user->getValue("Nachname");
}

$popup_height = $g_preferences['photo_show_height']+210;
$popup_width  = $g_preferences['photo_show_width']+70;
$bild         = $_REQUEST['photo'];

// Wenn der übergebene Bildernamen und die daszugehörige Photogallerie Id
// gültig ist dann wird der komplete Pfad für das Bild generiert
if(is_numeric($bild) && isset($_GET['pho_id']))
{
    $ordner_foto		= "/adm_my_files/photos/".$photo_event->getValue("pho_begin")."_".$photo_event->getValue("pho_id");
    $ordner_url			= $g_root_path.$ordner_foto;
    $bild_url			= "".$ordner_url."/".$_REQUEST['photo'].".jpg";
}
// Wenn nur der Bildernamen übergeben wird ist die Übergabe ungültig
if(is_numeric($bild) && !isset($_GET['pho_id']))
{
	$g_message->show("invalid");
}
// Wenn weder die Übergabe des Bildes noch die Photogallery id nummerisch sind -> ungültiger Aufruf
if(!is_numeric($bild) || !is_numeric($_GET['pho_id']))
{
	$g_message->show("invalid");
}
// Wenn ein Bilderpfad generiert worden ist dann können die Proportionalen Größen berechnet werden
if(isset($bild_url))
{
	list($width, $height)	= getimagesize($bild_url);
	$propotional_size_card	= getPropotionalSize($width, $height, $max_w_card, $max_h_card);
	$propotional_size_view	= getPropotionalSize($width, $height, $max_w_view, $max_h_view);
}

// ruf die Funktion auf die alle Post und Get Variablen parsed
getVars();
$ecard_send = false;
// Wenn versucht wird die GRußkarte zu versenden werden die notwendigen FElder geprüft und wenn alles okay ist wird das Template geparsed und die Grußkarte weggeschickt
if (! empty($submit_action)) 
{
	// Wenn die Felder Name E-mail von dem Empänger und Sender nicht leer sind
    if ( checkEmail($ecard["email_recepient"]) && checkEmail($ecard["email_sender"]) 
	&& ($ecard["email_recepient"] != "") && ($ecard["name_sender"] != "") )    
	{
		// Wenn die Nachricht größer ist als die maximal Länge wird sie zurückgestutzt
	    if (strlen($ecard["message"]) > $max_length) 
		{
	        $ecard["message"] = substr($ecard["message"],0,$max_length-1);
	    }
		// Template wird geparsed
		list($error,$ecard_data_to_parse) = getEcardTemplate($ecard["template_name"],$tmpl_folder);
		// Wenn es einen Error gibt ihn ausgeben
	    if ($error) 
	    {
		    $error_msg = $msg_error_1;
	    } 
		// Wenn nicht dann die Grußkarte versuchen zu versenden
	    else 
	    {
		    $ecard_html_data = parseEcardTemplate($ecard,$ecard_data_to_parse,$g_root_path,$g_current_user->getValue("usr_id"),$propotional_size_card['width'],$propotional_size_card['height']);
		    $result = sendEcard($ecard,$ecard_html_data,$ecard_plain_data);
			// Wenn die Grußkarte erfolgreich gesendet wurde 
		    if ($result) 
			{
			    $ecard_send = true;
		    } 
			// Wenn nicht dann die dementsprechende Error Nachricht ausgeben
			else 
		    {
			    $error_msg = $msg_error_1;
		    }
	   }
	}
	// Wenn die Felder leer sind oder ungültig dann eine dementsprechente Error Nachricht ausgeben
	else 
	{
        $error_msg = $msg_error_2;
	}
} 
// Wenn noch keine Anfrage zum versenden der Grußkarte vorhanden ist das Grußkarten Bild setzten
else 
{
    $ecard["image_name"] = $bild_url;
}

/*********************HTML_TEIL*******************************/

// Html-Kopf ausgeben
$g_layout['title'] = "Gru&szlig;karten";
//Lightbox-Mode
$g_layout['header'] = "";
if($g_preferences['photo_show_mode']==1)
{
    $g_layout['header'] .= "
        <script type=\"text/javascript\" src=\"".$g_root_path."/adm_program/libs/script.aculo.us/prototype.js\"></script>
        <script type=\"text/javascript\" src=\"".$g_root_path."/adm_program/libs/script.aculo.us/scriptaculous.js?load=effects\"></script>
        <script type=\"text/javascript\" src=\"".$g_root_path."/adm_program/libs/lightbox/lightbox.js\"></script>
        <link rel=\"stylesheet\" href=\"$g_root_path/adm_program/layout/lightbox.css\" type=\"text/css\" media=\"screen\" />";
}

$javascript='
    <script language="javascript" type="text/javascript">
        function popup_win(theURL,winName,winOptions) 
		{
             win = window.open(theURL,winName,winOptions);
             win.focus();
        }
        function sendEcard() 
        {
            if (check()) 
            { 
                document.ecard_form.action                 = "'.$HTTP_SERVER_VARS["PHP_SELF"].'?'.$_SERVER['QUERY_STRING'].'";
                document.ecard_form.target                 = "_self";
                document.ecard_form["submit_action"].value = "send";
                document.ecard_form.submit(); 
            }
            else
            {
                document.ecard_form["submit_action"].value = "";
            }
        } 
        function check() 
		{
            var error         = false;
            var error_message = "Du hast die folgenden, für die\nGrußkarte notwendigen Eingabefelder\nnicht bzw. nicht richtig ausgefüllt:\n\n";

            if (document.ecard_form["ecard[name_sender]"].value == "") 
			{
                error = true;
                error_message += "- name des Absenders\n";
            } 
 
            if ((document.ecard_form["ecard[email_sender]"].value == "") || 
               (echeck(document.ecard_form["ecard[email_sender]"].value) == false)) 
			{
                error = true;
                error_message += "- E-Mail des Absenders\n";
            }
  
            if (document.ecard_form["ecard[name_recepient]"].value == "" || document.ecard_form["ecard[name_recepient]"].value == "<Empfänger Name>") 
			{
                error = true;
                error_message += "- name des Empfängers\n";
            } 
            if ((document.ecard_form["ecard[email_recepient]"].value == "") || 
               (echeck(document.ecard_form["ecard[email_recepient]"].value) == false)) 
			{
                error = true;
                error_message += "- E-Mail des Empfängers\n";
        	}
        	if (document.ecard_form["ecard[message]"].value == "") 
			{
				error = true;
				error_message += "- eine Nachricht\n";
			}
			if (error) 
			{
				error_message += "\n\nBitte füll die genannten Eingabefelder\nvollständig aus und klick dann erneut\nauf \'Abschicken\'.";
				alert(error_message);
				return false;  // Formular wird nicht abgeschickt.
			} 
			else 
			{
				return true;  // Formular wird abgeschickt.
			}
			return false;
		} // Ende function check()
		function echeck(str) 
		{
			var at="@"
			var dot="."
			var lat=str.indexOf(at)
			var lstr=str.length
			var ldot=str.indexOf(dot)
			if (str.indexOf(at)==-1){
			return false
			}
			
			if (str.indexOf(at)==-1 || str.indexOf(at)==0 || str.indexOf(at)==lstr){
			return false
			}
			
			if (str.indexOf(dot)==-1 || str.indexOf(dot)==0 || str.indexOf(dot)==lstr){
			return false
			}
			
			if (str.indexOf(at,(lat+1))!=-1){
			return false
			}
			
			if (str.substring(lat-1,lat)==dot || str.substring(lat+1,lat+2)==dot){
			return false
			}
			
			if (str.indexOf(dot,(lat+2))==-1){
			return false
			}
			
			if (str.indexOf(" ")!=-1){
			return false
			}
			
			return true					
		}
		function makePreview() 
		{
			document.ecard_form.action = "ecard_preview.php?width='.$propotional_size_card['width'].'&height='.$propotional_size_card['height'].'&tmplfolder='.$tmpl_folder.'";
			popup_win(\''.$g_root_path.'/adm_program/ecards/templates/leer.htm\',\'ecard_preview\',\'resizable=yes,scrollbars=yes,width=800,height=600\');
			document.ecard_form.target = "ecard_preview";
			document.ecard_form.submit();
		}
        function blendout(id)
		{
		    if(document.getElementById(id).value == "<Empfänger Name>" || document.getElementById(id).value == "<Empfänger E-mail>")
			{
				document.getElementById(id).value = "";
			}
		}
		function countMax() 
		{
			max  = '.$max_length.';
			wert = max - document.ecard_form["ecard[message]"].value.length;
			if(document.ecard_form["ecard[message]"].value.length > max)
			{
				var txtvalue = document.ecard_form["ecard[message]"].value;
				document.ecard_form["ecard[message]"].value = txtvalue.substr(0, max);
			}
			if (wert < 0) 
			{
				alert("Die Nachricht darf maximal " + max + " Zeichen lang sein.!");
				wert = 0;
				document.ecard_form["ecard[message]"].value = document.ecard_form["ecard[message]"].value.substring(0,max);
				document.getElementById(\'counter\').innerHTML = \'<b>\' + wert + \'</b>\';
				wert = 0;
			} 
			else 
			{
			    var zwprodukt = max - document.ecard_form["ecard[message]"].value.length;
				document.getElementById(\'counter\').innerHTML = \'<b>\' + zwprodukt + \'</b>\';
			}
		} // Ende function countMax()

		function macheRequest(seite,divId)
		{
			var xmlHttp;
			try
			{
				// Firefox, Opera 8.0+, Safari
				xmlHttp=new XMLHttpRequest();
			}
			catch (e)
			{
				// Internet Explorer
				try
				{
					xmlHttp=new ActiveXObject("Msxml2.XMLHTTP");
				}
				catch (e)
				{
					try
					{
						xmlHttp=new ActiveXObject("Microsoft.XMLHTTP");
					}
					catch (e)
					{
						alert("Your browser does not support AJAX!");
						return false;
					}
				}
			}
			xmlHttp.onreadystatechange=function()
			{
				if(xmlHttp.readyState==4)
				{
					document.getElementById(divId).innerHTML = xmlHttp.responseText;
				}
			}
			xmlHttp.open("GET",seite,true);
			xmlHttp.send(null);
		}
		var basedropdiv = \'basedropdownmenu\';
		var dropdiv = \'dropdownmenu\';
		var externdiv = \'extern\';
		var switchdiv = \'exinswitch\';
		function getMenu()
		{
			macheRequest(\''.$g_root_path.'/adm_program/modules/ecards/ecard_drawdropmenue.php?base=1\' , \'basedropdownmenu\' );
			
		}
		function getMenuRecepientName()
		{
			macheRequest(\''.$g_root_path.'/adm_program/modules/ecards/ecard_drawdropmenue.php?rol_id=\'+ document.ecard_form.rol_id.value , \'dropdownmenu\' );
		}
		function getMenuRecepientNameEmail(usr_id)
		{
			macheRequest(\''.$g_root_path.'/adm_program/modules/ecards/ecard_drawdropmenue.php?usrid=\'+ usr_id, \'dropdownmenu\' );
			document.getElementById(externdiv).innerHTML = "&nbsp;";
		}
		function getTextStyle(textdiv)
		{
		 	var schrift_size = document.ecard_form["ecard[schrift_size]"].value;
			var schrift = document.ecard_form["ecard[schriftart_name]"].value;
			var schrift_farbe = document.ecard_form["ecard[schrift_farbe]"].value;
			var schrift_bold = "";
			var schrift_italic = "";
			if(document.ecard_form.Bold.checked)
			{
				schrift_bold = "bold"
			}
			if(document.ecard_form.Italic.checked)
			{
				schrift_italic = "italic";
			}
			var schrift_farbe = document.ecard_form["ecard[schrift_farbe]"].value;
			var schrift_farbe = document.ecard_form["ecard[schrift_farbe]"].value;
			document.getElementById(textdiv).style.font = schrift_bold + \' \'+ schrift_italic + \' \'+ schrift_size + \'px \'+schrift;
			document.getElementById(textdiv).style.color = schrift_farbe;	
		}
		function getSetting(name,input_value)
		{		
			document.ecard_form[name].value = input_value;	
			getTextStyle(\'Nachricht\');	
		}
		function getExtern()
		{
		    if(document.getElementById(basedropdiv).style.display == "none")
			{
				document.getElementById(basedropdiv).style.display = \'block\';
				document.getElementById(dropdiv).style.display = \'block\';
				document.getElementById(externdiv).style.display = \'none\';
				document.getElementById(externdiv).innerHTML = "&nbsp;";
				getMenu();
				document.getElementById(switchdiv).innerHTML = \'<a href="javascript:getExtern();">externer Empf&auml;nger</a>\';
			}
			else if(document.getElementById(basedropdiv).style.display == "block") 
			{
			    macheRequest(\''.$g_root_path.'/adm_program/modules/ecards/ecard_drawdropmenue.php?usrid=extern\', \'extern\' );
				document.getElementById(basedropdiv).style.display = \'none\';
				document.getElementById(dropdiv).style.display = \'none\';
				document.getElementById(externdiv).style.display = \'block\';
				document.getElementById(basedropdiv).innerHTML  = "&nbsp;";
				document.getElementById(dropdiv).innerHTML  = "&nbsp;";
				document.getElementById(switchdiv).innerHTML = \'<a href="javascript:getExtern();">interner Empf&auml;nger</a>\';
			}
		}
	</script>';
$g_layout['header'] .= $javascript;



//Photomodulspezifische CSS laden
$g_layout["header"] = $g_layout['header']."<link rel=\"stylesheet\" href=\"$g_root_path/adm_program/layout/photos.css\" type=\"text/css\" media=\"screen\" />";
 

if($g_preferences['photo_show_mode']==1)
{
    $g_layout['onload'] = " onload=\"initLightbox()\" ";
}

require(SERVER_PATH. "/adm_program/layout/overall_header.php");

echo '
<div class="formLayout" id="profile_form">
    <div class="formHead">';
	if(! empty($submit_action))
	{
	    echo "Grußkarte wegschicken";
	}
	else
	{
	    echo "Grußkarte bearbeiten";
	}
echo'
	</div>
	<div class="formBody">
	<div>';
if (empty($submit_action))
{   
	 //Popup-Mode
	if($g_preferences['photo_show_mode']==0)
	{
		echo "<img onclick=\"window.open('$g_root_path/adm_program/modules/photos/photo_presenter.php?bild=".$_REQUEST['photo']."&pho_id=".$_REQUEST['pho_id']."','msg','height=".$popup_height.", width=".$popup_width.",left=162,top=5')\" 
			 src=\"".$bild_url."\" width=\"".$propotional_size_view['width']."\" height=\"".$propotional_size_view['height']."\" style=\"border: 1px solid rgb(221, 221, 221); padding: 4px; margin: 10pt 10px 10px 10pt;\" alt=\"Grußkarte\" />";
	}
	//Lightbox-Mode
	if($g_preferences['photo_show_mode']==1)
	{
		echo "<a href=\"".$bild_url."\" rel=\"lightbox[roadtrip]\" title=\"".$photo_event->getValue("pho_name")."\"><img src=\"".$bild_url."\" width=\"".$propotional_size_view['width']."\" height=\"".$propotional_size_view['height']."\" style=\"border: 1px solid rgb(221, 221, 221); padding: 4px; margin: 10pt 10px 10px 10pt;\" alt=\"Grußkarte\" /></a>";
	}
	
	//Gleichesfenster-Mode
	if($g_preferences['photo_show_mode']==2)
	{
		echo "<img onclick=\"self.location.href='$g_root_path/adm_program/modules/photos/photo_presenter.php?bild=".$_REQUEST['photo']."&pho_id=$pho_id'\" src=\"".$bild_url."\" width=\"".$propotional_size_view['width']."\" height=\"".$propotional_size_view['height']."\" style=\"border: 1px solid rgb(221, 221, 221); padding: 4px; margin: 10pt 10px 10px 10pt;\" alt=\"Grußkarte\" />";
	}      
    if ($error_msg != "")
	{
		echo '<br /><span class="errorMsg">'.$error_msg.'</span>';
	}
	  
		echo' <form name="ecard_form" action="#" method="post">
			  <input type="hidden" name="ecard[image_name]" value="'; if (! empty($ecard["image_name"])) echo $ecard["image_name"]; echo'" />
			  <input type="hidden" name="submit_action" value="" />
			  <ul class="formFieldList">
			   <li>
                    <hr />
                </li>
               <li>
                 <dl>
                   <dt><label>An:</label>			        
				   </dt>
                   <dd>';
							if (array_key_exists("usr_id", $_GET))
                            {
                                // usr_id wurde uebergeben, dann E-Mail direkt an den User schreiben
								echo '<input type="text" class="readonly" readonly="readonly" name="ecard[name_recepient]" style="margin-bottom:3px; width: 200px;" maxlength="50" value="'.$user_name.'"><span class="mandatoryFieldMarker" title="Pflichtfeld">*</span>';
                                echo '<input type="text" class="readonly" readonly="readonly" name="ecard[email_recepient]" style="width: 350px;" maxlength="50" value="'.$user_email.'"><span class="mandatoryFieldMarker" title="Pflichtfeld">*</span>';
								
                            }
                            else
                            {
							   echo '<div style="height:48px; width:370px;">
									 <div id="exinswitch" style="float:right; margin-left:5px; display:relativ;">
										 <a href="javascript:getExtern()">externer Empf&auml;nger</a>
								     </div>
									 <div id="basedropdownmenu" style="display:block; margin-bottom:3px;">
									     <script language="javascript" type="text/javascript">getMenu();</script>
									 </div>
									 <div id="dropdownmenu" style="display:block;">
								     </div>
								     <div id="extern">
										 <input type="hidden" name="ecard[email_recepient]" value="" />
										 <input type="hidden" name="ecard[name_recepient]"  value="" />
									 </div>
									 </div>';
                            }
                            echo '
                        </dd>
                    </dl>
                </li>
				<li>
                    <hr />
                </li>
			    <li>
                    <dl>
                        <dt><label>Absender:</label></dt>
                        <dd>
			              <input type="text" name="ecard[name_sender]" size="25" class="readonly" readonly="readonly" maxlength="50" style="width: 200px;" value="'; 
							if (! empty($ecard["name_sender"]) && !$g_current_user->getValue("Nachname"))
							{
							   echo $ecard["name_sender"]; 
							}
						    else
							{ 
							   echo $g_current_user->getValue("Vorname")." ".$g_current_user->getValue("Nachname");
							}
					      echo'" />
						  <span class="mandatoryFieldMarker" title="Pflichtfeld">*</span>
			            </dd>
                    </dl>
                </li>
				 <li>
                    <dl>
                        <dt><label>E-Mail:</label></dt>
                        <dd>
						   <input type="text" name="ecard[email_sender]" size="25" class="readonly" readonly="readonly" maxlength="40" style="width: 350px;"  value="';
							if (! empty($ecard["email_sender"]) && !$g_current_user->getValue("E-Mail"))
							{
							  echo $ecard["email_sender"];
							}
							else 
							{
							  echo $g_current_user->getValue("E-Mail"); 
							}
						    echo'" />
							<span class="mandatoryFieldMarker" title="Pflichtfeld">*</span>
			            </dd>
                    </dl>
                </li>
                <li>
                    <hr />
                </li>
			    <li>
                    <dl>
                        <dt>
						    <label>Nachricht:</label>
							<div style="padding:70px 0px 40px 20px;">
							    noch&nbsp;<div id="counter" style="border:0px; display:inline;"><b>'; echo $max_length.'</b></div>&nbsp;Zeichen:
							</div>
						</dt>
                        <dd>
							<textarea id="Nachricht" style="width: 350px; height: 180px; overflow:hidden;" rows="10" cols="45" name="ecard[message]" onfocus="javascript:countMax();" onclick="javascript:countMax();" onchange="javascript:countMax();" onkeydown="javascript:countMax();" onkeyup="javascript:countMax();" onkeypress="javascript:countMax();" wrap="virtual">';
					  		if (! empty($ecard["message"])) 
							{
						 		echo ''.$ecard["message"].''; 
							}
					   echo'</textarea>
					        <span class="mandatoryFieldMarker" title="Pflichtfeld">*</span>
			           </dd>
                    </dl>
                </li>
				<li>
                    <hr />
                </li>
			    <li>
                    <dl>
                        <dt>
						    <label>Einstellungen:</label>
						</dt>
                        <dd>';
						    $first_value_array = array();
							echo'<table width="350px" cellpadding="5" cellspacing="0" summary="Einstellungen"  border="0px">
								<tr>
								  <td>Template:</td>
								  <td>Schriftart:</td>
								  <td>Schriftgröße:</td>
								</tr>
								<tr>
									<td>';
										array_push($first_value_array,array(getMenueSettings($templates,"ecard[template_name]","120","false"),"ecard[template_name]"));
									echo '</td>
									<td>';
										array_push($first_value_array,array(getMenueSettings($fonts,"ecard[schriftart_name]","120","true"),"ecard[schriftart_name]"));
									echo '</td>
									<td>';
										array_push($first_value_array,array(getMenueSettings($font_sizes,"ecard[schrift_size]","50","false"),"ecard[schrift_size]"));
								    echo  '</td>
							    </tr>
								<tr>
								  <td>Schriftfarbe:</td>
								  <td style="padding-left:40px;">Style:</td>
								  <td></td>
								</tr>
								<tr>
									<td>';
										array_push($first_value_array,array(getColorSettings($font_colors,"ecard[schrift_farbe]","8"),"ecard[schrift_farbe]"));
									echo '</td>
									<td colspan="2" style="padding-left:40px;">';
										echo '<b>Bold: </b><input name="Bold" value="bold" onclick="javascript: getSetting(\'ecard[schrift_style_bold]\',this.value);" type="checkbox" />											  <i>Italic: </i><input name="Italic" value="italic" onclick="javascript: getSetting(\'ecard[schrift_style_italic]\',this.value);" type="checkbox" />'; 					
									echo '</td>
							    </tr>
							</table>';
							getFirstSettings($first_value_array);
							echo '<input type="hidden" name="ecard[schrift_style_bold]" value="" />';		
							echo '<input type="hidden" name="ecard[schrift_style_italic]" value="" />';			
						echo '</dd>
                    </dl>
                </li>
			</ul> 
			<hr />
			</form>
			<div style="display:inline;">
				<button onclick="makePreview()" value="vorschau">
					<img src="'.$g_root_path.'/adm_program/images/eye.png" alt="Vorschau" />&nbsp;Vorschau
				</button>
			</div>
			<div style="display:inline;">
				<button onclick="sendEcard()" value="abschicken">
					<img src="'.$g_root_path.'/adm_program/images/email.png" alt="Abschicken" />&nbsp;Abschicken
				</button>
			</div>';
} 
else 
{     
	echo'<br />
	<div align="center"><span style="font-size:16px; font-weight:bold">Deine Gru&szlig;karte wurde erfolgreich versendet.</span></div>
	<br /><br />
	<table cellpadding="0" cellspacing="0" border="0" summary="Erfolg" align="center">
		<tr>
			<td align="left"><b>Absender:</b></td>
		</tr>
		<tr>
			<td align="left">'; echo $ecard["name_sender"].", ".$ecard["email_sender"]; echo'</td>
		</tr>
		<tr>
			<td align="left">&nbsp;</td>
		</tr>
		<tr>
			<td align="left"><b>Empfänger:</b></td>
		</tr>
		<tr>
			<td align="left">'; echo $ecard["name_recepient"].", ".$ecard["email_recepient"]; echo '</td>
		</tr>
	</table>
	<br /><br/>';
}  
echo "</div></div></div>";
/************************Buttons********************************/
//Uebersicht
if($photo_event->getValue("pho_id") > 0)
{
    echo "
    <ul class=\"iconTextLinkList\">
        <li>
            <span class=\"iconTextLink\">
                <a href=\"$g_root_path/adm_program/system/back.php\"><img 
                src=\"$g_root_path/adm_program/images/back.png\" alt=\"Zur&uuml;ck\"></a>
                <a href=\"$g_root_path/adm_program/system/back.php\">Zur&uuml;ck</a>
            </span>
        </li>
    </ul>";
}

/***************************Seitenende***************************/
require(SERVER_PATH. "/adm_program/layout/overall_footer.php");
?>