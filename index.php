<?php
	ini_set('display_errors', 1);
	error_reporting(E_ALL);


	//On config la locale pour le time
	date_default_timezone_set('Europe/Berlin');
	setlocale(LC_ALL, "fr_FR");


	if( isset($_POST) && isset($_POST['optionsPage']) && !empty($_POST['optionsPage']) ){

		$str_endDate = $_POST['optionsDateTo'];
		$str_startDate = $_POST['optionsDateFrom'];
		$str_pageToQuery = $_POST['optionsPage'];
		$str_typeQuery = $_POST['optionsType'];


		//On include l'api de facebook
		require_once("facebook/facebook.php");


		//On init facebook
		$config = array();
		$config['appId'] = '261787360642061';
		$config['secret'] = 'e075fff965588cc681278dae52f0cf78';
		$config['fileUpload'] = false; // optional
		$config['callback_url'] = "http://tests.dev/export-facebook/#";

		// On load l'api facebook
		$facebook = new Facebook($config);

		//On récupère l'user
		$user = $facebook->getUser();

		//On récupère les datas a afficher
		if ($user)
		{
			try
			{
				//On récupère les timeStamps de ces dates
				$temp = new DateTime($str_startDate);
				$str_startDateStamp = $temp->getTimestamp();
				$temp = new DateTime($str_endDate);
				$str_endDateStamp = $temp->getTimestamp();

				//On init ce qu'on a besoin
				$str_endDateFound = false;
				$feeds = array();
				$compteur = 0;

				//On récupère les infos de la page
				$infosPage = $facebook->api('/'.$str_pageToQuery,'GET');


				//on boucle pour récuperer les restaults
				while($str_endDateFound == false)
				{
					if($compteur == 0)
					{
						// Première query
						$feed_facebook = $facebook->api('/'.$str_pageToQuery.'/'.$str_typeQuery.'?until='.$str_endDateStamp,'GET');
					}
					else
					{
						if( isset($feed_facebook['paging']) ){
							//On utilise les next de FB
							$queryMore = $feed_facebook['paging']['next'];
							$queryMore = str_replace('https://graph.facebook.com', '', $queryMore);

							$feed_facebook = $facebook->api($queryMore,'GET');
						}
						else{
							$str_endDateFound = true;
							break;
						}

					}

					//On recupère leresultat
					$dataCount = count($feed_facebook['data']);

					if($dataCount <= 0 ){
						break;
					}

					//On recupère la date et le timestamp du dernier resultat
					$lastCreated = $feed_facebook['data'][$dataCount-1]['created_time'];
					$temp = new DateTime($lastCreated);
					$lastCreated = $temp->getTimestamp();

					//Si la date < date de début, on stop la bouche
					if( $lastCreated <= $str_startDateStamp)
					{
						$str_endDateFound = true;
					}

					//On push l'array dans l'array global
					$feeds = array_merge($feeds, $feed_facebook['data']);

					//On incrémente
					$compteur++;
				}

			}
			catch (FacebookApiException $e)
			{
				$user = null;
				$feed_facebook = null;
			}
		}

		if( isset($feed_facebook) ){


			//On inverse les resultats pour les avoir dans l'ordre chrono
			$feeds = array_reverse($feeds);


			//On init les varaibles pour le csv
			$file = 'export/export_'.$str_pageToQuery.'_'.date('d-m-Y').'.csv';


			//On supprime le csv si il existe
			if( file_exists($file) )
			{
				unlink($file);
			}


			//On change les infos du fichier
			touch($file);


			//On charge le fichier
			$fp	= fopen($file, "wb");


			//Forcer UTF8 for excel
			fwrite($fp, "\xEF\xBB\xBF", 3);


			//On ajoute le header
			$header	= array('Date','Image','Multiple','Vidéo','Liens Site','Emetteur','Wording','Like','Comm','Partage','Oui/Non','Nb','Descriptif');

			$data='';
			fputs($fp,chr(255).chr(254).iconv("UTF-8", "UTF-16LE//IGNORE", $data)."\n");

			fputcsv($fp, $header,';');

			$compteurFeed = 0;
			$oldName = "";
			$compteurOldName = 0;



			for($i = 0, $j = count($feeds); $i < $j; $i++)
			{
				$feed = $feeds[$i];
				$createdDate = $feed['created_time'];
				$temp = new DateTime($createdDate);
				$createdDate = $temp->getTimestamp();

				if( $createdDate > $str_startDateStamp)
				{

					$line = array();

					//La date
					array_push($line, utf8_encode(strftime('%A %d %B %Y', strtotime($feed['created_time']))));

					//Le type de contenu
					if( isset($feed['type']) )
					{
						switch($feed['type'])
						{
							case "photo":
								if( isset($feed['name']) && $feed['name'] == $oldName){
									array_push($line,1);
									array_push($line,1);
									array_push($line,0);
								}
								else{
									array_push($line,1);
									array_push($line,0);
									array_push($line,0);
								}

								if(isset($feed['name'])){
									$oldName = $feed['name'];
								}
								break;

							case "status":
								array_push($line,0);
								array_push($line,0);
								array_push($line,0);
								break;

							case "video":
								array_push($line,0);
								array_push($line,0);
								array_push($line,1);
								break;

							default:
								array_push($line,0);
								array_push($line,0);
								array_push($line,0);
								break;
						}
						$compteur++;
					}


					//Lien vers le site
					if( isset($feed['message']) && isset($infosPage['website']) )
					{
						//if( strpos($infosPage['website'],$feed['message'] ) === true){
						if( strpos( $feed['message'], 'http' ) !== false){
							array_push($line,1);
						}
						else{
							array_push($line,0);
						}
					}
					else{
						array_push($line,0);
					}


					//Le nom
					array_push($line,$feed['from']['name']);


					//Le message
					if( isset($feed['message']))
					{
						//$line .= '"'.addslashes(utf8_encode($feed['message'])).'";';
						$content = $feed['message'];
						array_push($line, $content);
					}
					else{
						array_push($line,'Vide');
					}


					//Nombre de like
					if( isset($feed['likes']))
					{
						array_push($line, count($feed['likes']['data']));
					}
					else{
						array_push($line,0);
					}


					//Nombre de commentaires
					if( isset($feed['comments']))
					{
						array_push($line, count($feed['comments']['data']));
					}
					else{
						array_push($line,0);
					}


					//Nombre de partage
					if( isset($feed['shares']))
					{
						array_push($line,$feed['shares']['count']);
					}
					else{
						array_push($line,0);
					}


					//Intéraction du CM
					// OUI / NON / Type
					$interaction = 0;
					$interactionType = '';
					if( isset($feed['likes']) && count($feed['likes']['data']) >0 )
					{
						foreach($feed['likes']['data'] as $like )
						{
							if($like['name'] == $infosPage['name'])
							{
								$interaction++;
							}
						}
						if( $interaction > 0 ){
							$interactionType = 'Like,';
						}
					}
					$interactionOld = $interaction;
					if( isset($feed['comments']) && count($feed['comments']['data']) >0 )
					{
						foreach($feed['comments']['data'] as $comment)
						{
							if($comment['from']['name'] == $infosPage['name'])
							{
								$interaction++;
							}
						}
						if( $interaction > $interactionOld ){
							$interactionType .= 'Commentaire';
						}
					}

					if( $interaction > 0)
					{
						array_push($line,'oui');
						array_push($line,$interaction);
						array_push($line,$interactionType);
					}
					else
					{
						array_push($line,'non');
						array_push($line,0);
						array_push($line,'Rien');
					}

					//On ecris la ligne
					fputcsv($fp, $line, ';');

					//On incrémente le compteur
					$compteurFeed++;

				}

			}


			//On save u csv
			if( fclose($fp))
			{
				//On crée le message d'affichage
				$message = '<p>Page: <a href="'.$infosPage['link'].'" target="_blank" >'.$infosPage['name'].'</a><br/>Likes: '.$infosPage['likes'].'</p>';
				$message .= '<p> Il y a '.$compteurFeed.' posts entre le '.$str_startDate.' et le '.$str_endDate.'.</p>';
				$message .= '<a href="'.$file.'"> Télécharger l\'export csv </a>';
			}
			else{
				$message = '<p class="warning">ERREUR d\'enregistrement du fichier';
			}
		}
		else{
			$message = '<p class="warning">Merci de vous connecter</p>';
			if ($user) {
				$logoutUrl = $facebook->getLogoutUrl();
			} else {
				$loginUrl = $facebook->getLoginUrl();
			}

		}
	}



?>


<!doctype html>
<!-- paulirish.com/2008/conditional-stylesheets-vs-css-hacks-answer-neither/ -->
<!--[if lt IE 7]> <html class="no-js lt-ie9 lt-ie8 lt-ie7" lang="fr" xml:lang="fr" dir="ltr" > <![endif]-->
<!--[if IE 7]>    <html class="no-js lt-ie9 lt-ie8" lang="fr" xml:lang="fr" dir="ltr" > <![endif]-->
<!--[if IE 8]>    <html class="no-js lt-ie9" lang="fr" xml:lang="fr" dir="ltr" > <![endif]-->
<!--[if gt IE 8]><!--> <html class="no-js" xml:lang="fr" lang="fr" dir="ltr" > <!--<![endif]-->
<head>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
	<meta content="width=device-width, initial-scale=1" name="viewport" />
	<meta content="IE=edge,chrome=1" http-equiv="X-UA-Compatible" />
	<title>Export des posts d'une page facebook - Casus Belli</title>

	<link href='http://fonts.googleapis.com/css?family=Open+Sans:300italic,400,300,600' rel='stylesheet' type='text/css'>

	<link href='css/knacss.css' rel='stylesheet' type='text/css'>
	<link href='css/global.css' rel='stylesheet' type='text/css'>

	<link rel="stylesheet" href="http://code.jquery.com/ui/1.10.3/themes/smoothness/jquery-ui.css" />
	<script src="http://code.jquery.com/jquery-1.9.1.js"></script>
	<script src="http://code.jquery.com/ui/1.10.3/jquery-ui.js"></script>
	<script>
		$(function() {
			$( ".date" ).datepicker();
		});
  </script>
</head>

<body>
	<section class="content">
		<header>
			<h1>Export d'une page Facebook</h1>
		</header>

		<?php if( isset($message) && !empty($message) ): ?>
			<section class="message">
				<?php echo $message; ?>
				<?php if (!$user): ?>
				<div>
					Connexion à Facebook avec OAuth 2.0 de l'API
					<a href="<?php echo $loginUrl; ?>">Connexion</a>
				</div>
			<?php endif ?>
			</section>
		<?php endif; ?>


		<form method="post" action="#" id="formOptions" name="formOptions">
			<p class="element">
				<label for="optionsPage">Identifiant de la page Facebook</label>
				<input type="text" required="required" name="optionsPage" id="optionsPage" value="<?php if( isset($str_pageToQuery) ){ echo $str_pageToQuery; } ?>" placeholder="Jardiland.Page.Officielle" />
			</p>

			<p class="element">
				<label for="optionsDateFrom">Date de début de la recherche</label>
				<input type="text" class="date" required="required" value="<?php if( isset($str_startDate) ){ echo $str_startDate; } ?>" name="optionsDateFrom" id="optionsDateFrom" placeholder="10/07/2012" />
			</p>

			<p class="element">
				<label for="optionsDateTo">Date de fin de la recherche</label>
				<input type="text" class="date" required="required" value="<?php if( isset($str_endDate) ){ echo $str_endDate; } ?>" name="optionsDateTo" id="optionsDateTo" placeholder="10/07/2013" />
			</p>

			<p class="element">
				<label for="optionsType">Type de contenu a extraire</label>
				<select id="optionsType" name="optionsType" required="required" placeholder="Choisir une valeur">
					<option value="feed">Feed</option>
				 	<option value="posts">Posts</option>
				 	<option value="statuses">statuses</option>
				</select>
			</p>

			<p class="submit">
				<input type="submit" name="optionsSubmit" id="optionsSubmit" value="Récuperer" />
			</p>
		</form>

	</section>
</body>

</html>