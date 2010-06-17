<?php
include_once("../arc/ARC2.php");

$DEBUG = false;

// web app params
$VOX_BASE = "vox/";
$TEMPLATE_BASIC = "void-desc-basic.html";

// DBPedia lookup interface
$BASE_DBPEDIA_LOOKUP_URI = "http://lookup.dbpedia.org/api/search.asmx/KeywordSearch?QueryClass=string&MaxHits=5&QueryString=";

// voiD stores interface
$BASE_TALIS_LOOKUP_URI ="http://api.talis.com/stores/kwijibo-dev3/services/sparql?output=json&query=";
$BASE_RKB_LOOKUP_URI = "http://void.rkbexplorer.com/sparql/?format=json&query=";

$defaultprefixes = "PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#> PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#> PREFIX dcterms: <http://purl.org/dc/terms/> PREFIX foaf: <http://xmlns.com/foaf/0.1/> PREFIX void: <http://rdfs.org/ns/void#> PREFIX dbpedia-owl: <http://dbpedia.org/ontology/> ";

/* ARC2 RDF store config - START */
$config = array(
	'db_name' => 'arc2',
	'db_user' => 'root',
	'db_pwd' => 'root',
	'store_name' => 'vox'
); 

$store = ARC2::getStore($config);

if (!$store->isSetUp()) {
  $store->setUp();
  echo 'set up';
}
/* ARC2 RDF store config - END */


/* voX INTERFACE */

//// GET interface

if(isset($_GET['reset'])) {
	$store->reset();
	echo "RESET store done.<br />\n";
	echo "<p>go <a href='index.html'>home</a> ...</p>\n";     
}

if(isset($_GET['uri'])){
	echo renderVoiD($_GET['uri']);
}

if(isset($_GET['topic'])){
	echo getTopicDescription($_GET['topic']);
}

/* voX METHODS */



function renderVoiD($voidURI){
	global $DEBUG;
	global $TEMPLATE_BASIC;	
	global $store;
	global $defaultprefixes;

	$entityConceptList = array();

	if(!isDataLocal($voidURI)) { // we haven't tried to dereference the voiD URI yet
		loadData($voidURI); //... hence we dereference it and load it into the store
	}
	
	$cmd = $defaultprefixes;
	$cmd .= "SELECT DISTINCT *  FROM <" . $voidURI . "> WHERE "; 
	$cmd .= "{ ?ds a void:Dataset ;  
		OPTIONAL { ?ds dcterms:title ?title ; }
		OPTIONAL { ?ds dcterms:description ?description ; }
		OPTIONAL { ?ds dcterms:date ?date ; }
		OPTIONAL { ?ds foaf:homepage ?homepage ;}
		OPTIONAL { ?ds dcterms:subject ?topic ;}
		OPTIONAL { ?ds void:vocabulary ?vocabulary ;}
		OPTIONAL { ?ds void:exampleResource ?exampleRes ;}
		OPTIONAL { ?ds void:sparqlEndpoint ?sparqlEndpoint ;}
		OPTIONAL { ?ds void:uriRegexPattern ?uriRegEx ;}
	}";
	
	if($DEBUG) echo htmlentities($cmd) . "<br />";
	
	$results = $store->query($cmd);
	
	$retVal = "<p style='padding-left: 10px'>The voiD file <a href='$voidURI' title='voiD file'>$voidURI</a> contains the following dataset descriptions:</p><div class='dsdescription'>";
	$dsList = array();
	$dsURI = "";
	$dsTitle = "???";
	$dsDesc = "???";
	$dsDate = "???";
	$dsHomePage = "";
	$dsSPARQLEndpoint = "";
	$dsDataset2Topics = array();

	if($results['result']['rows']) {
		foreach ($results['result']['rows'] as $row) {
			$dsURI = $row['ds'];
			
			if(!in_array($dsURI, $dsList)) { // remember dataset, pull global info and pre-fill template
				array_push($dsList, $dsURI);
				
				if($row['title']) $dsTitle = $row['title'];
				if($row['description']) $dsDesc = $row['description'];
				if($row['date']) $dsDate = $row['date'];
				if($row['homepage']) $dsHomePage = $row['homepage'];
				if($row['sparqlEndpoint']) $dsSPARQLEndpoint = $row['sparqlEndpoint'];
				
				$descTemplate = file_get_contents($TEMPLATE_BASIC);
				$search  = array('%DATASET_URI%', '%DATASET_TITLE%', '%DATASET_DESCRIPTION%', '%DATASET_DATE%', '%DATASET_HOMEPAGE%', '%DATASET_SPARQLEP%');
				$replace = array($dsURI, $dsTitle, $dsDesc, $dsDate, $dsHomePage, $dsSPARQLEndpoint);
				$retVal .= "<h1>$dsURI</h1>";
				$retVal .= str_replace($search, $replace, $descTemplate);
			}
			if(isset($dsDataset2Topics[$dsURI])){
				if(!in_array($row['topic'], $dsDataset2Topics[$dsURI])){ // remember topics of a dataset
					array_push($dsDataset2Topics[$dsURI], $row['topic']); 
					$retVal .= getTopicDescription($row['topic']);  
				}	
			}
			else {
				$dsDataset2Topics[$dsURI] = array();
				array_push($dsDataset2Topics[$dsURI], $row['topic']);
				if($row['topic']) {
					$retVal .= "<h2>Topics</h2>";
					$retVal .= "<p class='topic'>The dataset is about:</p>";
					$retVal .=  getTopicDescription($row['topic']);  
				}
				else {
					$retVal .= "<p class='topic'>The dataset topic is unknown.</p>";
				}
			}
		}
	}
	else $retVal = "<p>Sorry, didn't find any dataset descriptions.</p>";
		
	return $retVal . "<div class='sectseparator'></div></div>";
}

// dereferences topic resource and retrieves dcterms:title and/or rdfs:label of the topic resource
function getTopicDescription($topicURI){
	global $DEBUG;
	global $store;
	global $defaultprefixes;
	
	if(!isDataLocal($topicURI)) { // we haven't tried to dereference the topic URI yet
		loadData($topicURI); // ... hence we dereference it and load it into the store
	}
	
	$cmd = $defaultprefixes;
	$cmd .= "SELECT DISTINCT * FROM <" . $topicURI . "> WHERE "; 
	$cmd .= "{  
		<" . $topicURI . "> rdfs:label ?title .
		OPTIONAL {	<" . $topicURI . "> dbpedia-owl:abstract ?abstract ; }
		FILTER langMatches( lang(?title), 'EN' )
		FILTER langMatches( lang(?abstract), 'EN' )
	}";
	
	if($DEBUG) echo htmlentities($cmd) . "<br />";
	
	$results = $store->query($cmd);
	
	if($results['result']['rows']) {
		foreach ($results['result']['rows'] as $row) {
			if($row['title']) {
				if($row['abstract']) $abstract = $row['abstract'];
				else $abstract = "???";  
				return "<div resource='$topicURI' class='dstopic'><a href='$topicURI' target='_new'>". $row['title'] . "</a> <span class='ui-state-default ui-corner-all smallbtn' title='details'>+</span><div class='topicdetails'>$abstract</div></div>";
				
			}
			else return "Didn't find the topic title, sorry ..."; 
		}
	}
	else return "<div resource='$topicURI' class='dstopic'><a href='$topicURI' target='_new'>$topicURI</a> ...</div>";

}

// low-level ARC2 store methods
function isDataLocal($graphURI){
	global $store;
	
	$cmd = "SELECT ?s FROM <$graphURI> WHERE { ?s ?p ?o .}";

	$results = $store->query($cmd);
	
	if($results['result']['rows']) return true;
	else return false;
}

function loadData($dataURI) {
	global $store;
	global $DEBUG;
	
	$cmd .= "LOAD <$dataURI> INTO <$dataURI>"; 
	
	if($DEBUG) echo htmlentities($cmd) . "<br />";

	$store->query($cmd);
	$errs = $store->getErrors();
	
	return $errs;
}


// various utility methods
function lookupSubjectInDBPedia($keyword){
	global $BASE_DBPEDIA_LOOKUP_URI;
	global $DEBUG;
	
	$matches = array();
	
	$data = file_get_contents($BASE_DBPEDIA_LOOKUP_URI . $keyword);
	$parser = xml_parser_create();
	xml_parse_into_struct($parser, $data, $values);
	xml_parser_free($parser);
	for( $i=0; $i < count($values); $i++ ){
		$match = array();
		
		if($values[$i]['tag'] == strtoupper("Description")) {
			$desc =  $values[$i]['value'];
		}
		if($values[$i]['tag']==strtoupper("Label")){
			$label =  $values[$i]['value'];
		}
		if($values[$i]['tag']==strtoupper("URI") && 
			(strpos($values[$i]['value'], "http://dbpedia.org/resource") == 0) &&
			(strpos($values[$i]['value'], "Category:") === false)
		){ // use only resource URIs and exclude category resource URIs
			$URI =  $values[$i]['value'];
		}
		
		if(isset($URI) && isset($desc)&& isset($label)) {
			$match['URI'] = $URI;
			$match['label'] = $label;
			$match['desc'] = $desc;
			
			array_push($matches, $match);
			if($DEBUG) {
				echo "<strong>" . $URI . "</strong>:<p>" . $desc . "</p>" ;
			}
			unset($URI);
			unset($desc);
			unset($label);
		}
	}
	return json_encode($matches);
}



function listSPARQLEndpoints($lookupURI){
	global $DEBUG;
	$ret = array();
			
	$query = "SELECT DISTINCT ?endpoint ?ds WHERE { ?ds a <http://rdfs.org/ns/void#Dataset> ; <http://rdfs.org/ns/void#sparqlEndpoint> ?endpoint . }";
	
	if($DEBUG) echo $query . "<br />\n";
	
	$jsondata = file_get_contents($lookupURI . urlencode($query));
	if($DEBUG) var_dump($jsondata);
	
	$data = json_decode($jsondata, true); 	
	
	foreach($data["results"]["bindings"] as $binding){
		$endpointList["ds"] =  $binding["ds"]["value"];		
		$endpointList["endpoint"] =  $binding["endpoint"]["value"];
		$ret[] = $endpointList;
	}
	
	return json_encode($ret);
}

function executeQuery($eParams){
	global $DEBUG;

	$endpointURI = $eParams["endpointURI"];
	$queryStr = $eParams["queryStr"];

	$c = curl_init();
	curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($c, CURLOPT_HEADER, 0);
	curl_setopt($c, CURLOPT_URL, $endpointURI . urlencode($queryStr));
	curl_setopt($c, CURLOPT_TIMEOUT, 30);
	$result = curl_exec($c);
	curl_close($c);
	return $result;
}



?>