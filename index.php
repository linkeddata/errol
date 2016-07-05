<?
require_once('init.php');

EasyRdf_Namespace::set('solid', 'http://www.w3.org/ns/solid/terms#');
EasyRdf_Namespace::set('ldp', 'http://www.w3.org/ns/ldp#');

function inbox_from_header($url, $rel="http://www.w3.org/ns/ldp#inbox"){
  if(isset($_SESSION[$rel])){
    return $_SESSION[$rel];
  }else{
    $res = head_http_rels($url);
    $rels = $res['rels'];
    return $rels[$rel][0];
  }
}

function get_inbox($url){

  $inbox = inbox_from_header($url);
  if(empty($inbox)){

    $ch = curl_init();
  	curl_setopt($ch, CURLOPT_URL, $url);
  	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  	$data = curl_exec($ch);
  	$ct = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
  	curl_close($ch);
  	
  	$cts = explode(';', $ct);
  	if(count($cts) > 1){
    	foreach($cts as $act){
    	  $act = trim($act);
      	try {
      	  if(EasyRdf_Format::getFormat($act)){
      	    $ct = $act;
      	    break;
      	  }
      	}catch(Exception $e){}
    	}
  	}
  	$graph = new EasyRdf_Graph();
  	
    try{
      $graph->parse($data, $ct, $url);
    } catch (Exception $e) {
      return $e->getMessage();
    }
    
    $subject = $graph->resource($url);
  	$inbox = $subject->get('solid:inbox');

  }
	return $inbox;
}

function make_notification_as($post){
  
  $graph = new EasyRdf_Graph();
  EasyRdf_Namespace::set('as', 'http://www.w3.org/ns/activitystreams#');
  
  $notif = $graph->resource('placeholder', 'solid:Notification');
  
  if($post['source']){
    $graph->add($notif, 'rdf:type', $graph->resource('as:Announce'));
    $graph->add($notif, 'as:object', $graph->resource($post['source']));
    if($post['object'] != ""){
      $graph->add($notif, 'as:target', $graph->resource($post['object']));
    }
    if($post['inReplyTo'] != ""){
      $graph->add($notif, 'as:target', $graph->resource($post['inReplyTo']));
      $graph->add($graph->resource($post['source']), 'as:inReplyTo', $graph->resource($post['inReplyTo']));
    }
  }elseif($post['object'] != ""){
    $graph->add($notif, 'as:object', $graph->resource($post['object']));
  }
  
  if($post['to'] != ""){
    $graph->add($notif, 'as:to', $graph->resource($post['to']));
  }
  
  if($post['inReplyTo'] != "" && $post['source'] == ""){
    $graph->add($notif, 'as:inReplyTo', $graph->resource($post['inReplyTo']));
  }
  
  if($post['content'] != ""){
    $graph->addLiteral($notif, 'as:content', $post['content']);
    if($post['inReplyTo'] != "" || $post['to'] != ""){
      $graph->add($notif, 'rdf:type', $graph->resource('as:Note'));
    }
  }
  
  // TODO: Only set the actor if can be verified by authentication.
  //       For now, nobody knows I'm a dog.
  if($post['actor'] != ""){
    $graph->add($notif, 'as:actor', $graph->resource($post['actor']));
  }
  
  $date = EasyRdf_Literal::create(date(DATE_ATOM), null, 'xsd:dateTime');
  $graph->add($notif, 'as:published', $date);
  
  // TODO: Ask receiver if it can take turtle
  $normed = $graph->serialise('application/ld+json');
  $cmp = \ML\JsonLD\JsonLD::compact($normed, "https://www.w3.org/ns/activitystreams");
  $str = \ML\JsonLD\JsonLD::toString($cmp, true);
  return $str;
}

function make_notification_pingback($post){
  
  $graph = new EasyRdf_Graph();
  EasyRdf_Namespace::set('pingback', 'http://purl.org/net/pingback/');
  
  $notif = $graph->resource('placeholder', 'solid:Notification');
  $graph->add($notif, 'rdf:type', $graph->resource('pingback:Request'));
  $graph->add($notif, 'pingback:source', $graph->resource($post['source']));
  $graph->add($notif, 'pingback:target', $graph->resource($post['target']));
  
  $date = EasyRdf_Literal::create(date(DATE_ATOM), null, 'xsd:dateTime');
  $graph->add($notif, 'dct:created', $date);
  
  $normed = $graph->serialise('application/ld+json');
  $cmp = \ML\JsonLD\JsonLD::compact($normed, "http://purl.org/net/pingback/");
  $str = \ML\JsonLD\JsonLD::toString($cmp, true);
  return $str;
}

function make_notification_sioc($post){
  
  $graph = new EasyRdf_Graph();
  EasyRdf_Namespace::set('sioc', 'http://rdfs.org/sioc/ns#');
  EasyRdf_Namespace::set('dct', 'http://purl.org/dc/terms/');
  
  $notif = $graph->resource('placeholder', 'solid:Notification');
  $graph->add($notif, 'rdf:type', $graph->resource('sioc:Post'));
  if($post['title'] != ""){
    $graph->addLiteral($notif, 'dct:title', $post['title']);
  }
  if($post['content'] != ""){
    $graph->addLiteral($notif, 'sioc:content', $post['content']);
  }
  if($post['creator'] != ""){
    $graph->add($notif, 'dct:creator', $graph->resource($post['creator']));
  }
  $date = EasyRdf_Literal::create(date(DATE_ATOM), null, 'xsd:dateTime');
  $graph->add($notif, 'dct:created', $date);
  
  $normed = $graph->serialise('application/ld+json');
  $cmp = \ML\JsonLD\JsonLD::compact($normed, "http://rdfs.org/sioc/ns#");
  $str = \ML\JsonLD\JsonLD::toString($cmp, true);
  return $str;
  
}

function make_notification_triple($post){
  
  $graph = new EasyRdf_Graph();
  $subj = $graph->resource($post['subject']);
  $pred = $graph->resource($post['predicate']);
  $obj = $graph->resource($post['object']);
  $graph->add($subj, $pred, $obj);
  
  $normed = $graph->serialise('application/ld+json');
  $cmp = \ML\JsonLD\JsonLD::compact($normed);
  $str = \ML\JsonLD\JsonLD::toString($cmp, true);
  return $str;
  
}

function write_notification($inbox, $turtle){
  
  $turtle = str_replace('placeholder', '', $turtle);
  
  $ch = curl_init();
  curl_setopt($ch,CURLOPT_URL, $inbox);
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
  curl_setopt($ch, CURLOPT_POSTFIELDS, $turtle);
  curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    'Content-Type: applicaton/ld+json',
    'Content-Length: ' . strlen($turtle))
  );
  $result = curl_exec($ch);
  $info = curl_getinfo($ch);
  curl_close($ch);
  return $info['http_code'];
}

function make_notification($post){
  
  $notification = "";
  $errors = array();

  if(isset($post) && count($post) > 0){
    
    if(isset($post['sendAs'])){
      
      if($post['to'] == "" && $post['inReplyTo'] == "" && $post['object'] == ""){
        $errors['to'] = "Must include one or more of to or in reply to or about.";
      }
      if($post['source'] == "" && $post['content'] == ""){
        $errors['source'] = "Must include source and/or content.";
      }
      if(count($errors) < 1){
        $notification = make_notification_as($post);
      }
    
    }elseif(isset($post['sendPingback'])){
      
      if($post['source'] == "" || $post['target'] == ""){
        $errors['pingback'] = "Must include source and target.";
      }
      if(count($errors) < 1){
        $notification = make_notification_pingback($post);
      }
      
    }elseif(isset($post['sendSioc'])){
      
      if($post['to'] == ""){
        $errors['to'] = "Must include to.";
      }
      
      if($post['title'] == "" && $post['content'] == ""){
        $errors['sioc'] = "Must include title or content.";
      }
      if(count($errors) < 1){
        $notification = make_notification_sioc($post);
      }
      
    }elseif(isset($post['sendTriple'])){
      
      if($post['subject'] == "" || $post['predicate'] == "" || $post['object'] == ""){
        $errors['triple'] = "Subject, predicate and object are required.";
      }
      if(count($errors) < 1){
        $notification = make_notification_triple($post);
        var_dump($notification);
      }
      
    }
  }
    
    return array("notification"=>$notification, "errors"=>$errors);
}

function route($post){
  
  $inbox = "";
  $errors = array();
  if(isset($post['to']) && $post['to'] != ""){
    $to = $post['to'];
  }elseif(isset($post['object']) && $post['object'] != ""){
    $to = $post['object'];
  }elseif(isset($post['target']) && $post['target'] != ""){
    $to = $post['target'];
  }elseif(isset($post['inReplyTo']) && $post['inReplyTo'] != ""){
    $to = $post['inReplyTo'];
  }
  if(isset($to)){
    $inbox = get_inbox($to);
    if(!isset($inbox)){
      $errors['inbox'] = "No inbox found for $to :(";
    }
  }else{
    $errors['inbox'] = "Nowhere to look for an inbox (need to, object, target or inReplyTo).";
  }
  return array("inbox"=>$inbox, "errors"=>$errors);
}

$prefill = array();
if(isset($_GET) && count($_GET) > 0){
  $prefill = $_GET;
}
if($_SERVER['REQUEST_METHOD'] == "POST" && count($_POST) > 0){
  
  $prefill = $_POST;
  $route = route($_POST);
  $notif = make_notification($_POST);
  
  if(count($route['errors']) < 1 && count($notif['errors']) < 1){
    $write = write_notification($route['inbox'], $notif['notification']);
    if($write >= 200 && $write < 300){
      $success = "Posted!";
      $inbox = $route['inbox'];
    }else{
      $errors['write'] = "Notification not posted ($write).";
    }
  }else{
    $errors = array_merge($route['errors'], $notif['errors']);
    //var_dump($errors);
  }
}

?>
<!doctype html>
<html>
  <head>
    <title>Errol</title>
    <link rel="stylesheet" href="style.css" />
  </head>
  <body>
    <header>
      <img src="owl.jpg" width="100" />
      <h1>Errol</h1>
      <p>Send Linked Data Notifications to any inbox.</p>
    </header>
    <div>
      <?if(isset($inbox)):?>
        <p>Posting to: <code><?=$inbox?></code></p>
      <?endif?>
      <?if(isset($errors['inbox'])):?>
        <p class="error"><?=$errors['inbox']?></p>
      <?endif?>
      <?if(isset($notification)):?>
        <pre>
          <?=htmlentities($notification)?>
        </pre>
      <?endif?>
    </div>
    <?if(isset($errors['parsing'])):?>
      <div class="error">
        <p><strong>Parsing error</strong>: <?=$errors['parsing']?></p>
      </div>
    <?endif?>
    <?if(isset($errors['write'])):?>
      <div class="error">
        <p><strong>Writing error</strong>: <?=$errors['write']?></p>
      </div>
    <?endif?>
    <?if(isset($success)):?>
      <div class="success">
        <p><?=$success?></p>
      </div>
    <?endif?>
    <nav id="links">
      <ul>
        <li><a href="#As" id="linkAs">ActivityStreams2</a></li>
        <li><a href="#Pingback" id="linkPingback">Pingback</a></li>
        <li><a href="#Sioc" id="linkSioc">Sioc</a></li>
        <li><a href="#Triple" id="linkTriple">Triple</a></li>
      </ul>
    </nav>
    <form method="post" id="formAs">
      <h2>ActivityStreams2</h2>
      <p>All fields are optional, but you must include either <em>to</em> (person) or <em>in reply to</em> or <em>object</em> (resource), and you must include either <em>content</em> or a <em>source</em>.</p>
      <?=isset($errors['to']) ? '<div class="error"><p>'.$errors['to'].'</p>' : ""?>
        <p><label for="to">To</label> <input name="to" type="url" placeholder="WebID of a person" value="<?=isset($prefill['to']) ? $prefill['to'] : ''?>"/></p>
        <p><label for="inReplyTo">In reply to</label> <input name="inReplyTo" type="url" placeholder="URI of a resource that this notification is in reply to" value="<?=isset($prefill['inReplyTo']) ? $prefill['inReplyTo'] : ''?>" /></p>
        <p><label for="object">Object</label> <input name="object" type="url" placeholder="URI of a resource that this notification is about (but not a reply)" value="<?=isset($prefill['object']) ? $prefill['object'] : ''?>" /></p>
      <?=isset($errors['to']) ? '</div>' : ""?>
      <?=isset($errors['source']) ? '<div class="error"><p>'.$errors['source'].'</p>' : ""?>
        <p><label for="source">Source</label> <input name="source" type="url" placeholder="URI of a resource with additional relevent information" value="<?=isset($prefill['source']) ? $prefill['source'] : ''?>" /></p>
        <p><label>Content</label> <textarea name="content"><?=isset($prefill['content']) ? $prefill['content'] : ''?></textarea></p>
      <?=isset($errors['source']) ? '</div>' : ""?>
      <p><label>Your URI</label> <input name="actor" type="url" value="<?=isset($prefill['actor']) ? $prefill['actor'] : ''?>" /></p>
      <p><input type="submit" id="sendAs" name="sendAs" value="Send" /></p>
    </form>
    
    <form method="post" id="formPingback">
      <h2>Pingback</h2>
      <p><em>Source</em> and <em>target</em> are required.</p>
      <?=isset($errors['pingback']) ? '<div class="error"><p>'.$errors['pingback'].'</p>' : ""?>
        <p><label>Source</label> <input name="source" type="url" placeholder="Where this notification is coming from" value="<?=isset($prefill['source']) ? $prefill['source'] : ''?>" /></p>
        <p><label>Target</label> <input name="target" type="url" placeholder="Where this notification is pointing to" value="<?=isset($prefill['target']) ? $prefill['target'] : ''?>" /></p>
        <p><input type="submit" id="sendPingback" name="sendPingback" value="Send" /></p>
    </form>
    
    <form method="post" id="formSioc">
      <h2>Sioc</h2>
      <p><em>To</em> is required and one of <em>title</em> and <em>content</em> is required.</p>
      <?=isset($errors['to']) ? '<div class="error"><p>'.$errors['to'].'</p>' : ""?>
        <p><label>To</label> <input name="to" type="url" placeholder="WebID of receiver" value="<?=isset($prefill['to']) ? $prefill['to'] : ''?>" /></p>
      <?=isset($errors['to']) ? '</div>' : ""?>
      <p><label>From</label> <input name="creator" type="url" placeholder="WebID of author of message" value="<?=isset($prefill['creator']) ? $prefill['creator'] : ''?>" /></p>
      <?=isset($errors['sioc']) ? '<div class="error"><p>'.$errors['sioc'].'</p>' : ""?>
        <p><label>Title</label> <input name="title" type="text" placeholder="Name for this message" value="<?=isset($prefill['title']) ? $prefill['title'] : ''?>" /></p>
        <p><label>Content</label> <textarea name="content"><?=isset($prefill['content']) ? $prefill['content'] : ''?></textarea></p>
      <?=isset($errors['sioc']) ? '</div>' : ""?>
        <p><input type="submit" id="sendSioc" name="sendSioc" value="Send" /></p>
    </form>
    
    <form method="post" id="formTriple">
      <h2>Triple</h2>
      <p>All fields required. Sends a single triple statement.</p>
      <?=isset($errors['triple']) ? '<div class="error"><p>'.$errors['triple'].'</p>' : ''?>
        <p><label>Subject</label> <input name="subject" type="url" placeholder="Subject of this notification" value="<?=isset($prefill['subject']) ? $prefill['subject'] : ''?>" /></p>
        <p><label>Predicate</label> <input name="predicate" type="url" placeholder="Relation between subject and object (full uri including namespace)" value="<?=isset($prefill['predicate']) ? $prefill['predicate'] : ''?>" /></p>
        <p><label>Object</label> <input name="object" type="url" placeholder="Object of this notification" value="<?=isset($prefill['object']) ? $prefill['object'] : ''?>" /></p>
      <?=isset($errors['triple']) ? '</div>' : ''?>
      <p><input type="submit" id="sendTriple" name="sendTriple" value="Send" /></p>
    </form>
    
    <footer>
      <ul>
        <li><a href="https://github.com/linkeddata/errol">Source</a></li>
        <li><a href="https://github.com/linkeddata/errol/issues">Issues</a></li>
        <li><a href="https://github.com/solid">Solid</a></li>
        <li><a href="https://rawgit.com/csarven/ldn/master/index.html">Linked Data Notifications</a></li>
      </ul>
    </footer>
    
    <script>
      
      function toggle(name){
        
        var nav = document.getElementById('links');
        var links = nav.querySelectorAll('a');
        var forms = document.querySelectorAll('form');
        for(var i = 0; i < forms.length; i++){
          forms[i].style.display = 'none';
        }
        for(var i = 0; i < links.length; i++){
          links[i].style.backgroundColor = 'white';
        }
        document.getElementById('form'+name).style.display = 'block';
        document.getElementById('link'+name).style.backgroundColor = 'silver';
        
      }
      
      if(window.location.hash != ""){
        toggle(window.location.hash.substring(1));
      }else{
        toggle('As');
      }
      
      var nav = document.getElementById('links');
      var links = nav.querySelectorAll('a');
      for(var i = 0; i < links.length; i++){
        links[i].addEventListener('click', function(){
          toggle(this.hash.substring(1));
        });
      }
      
    </script>
  </body>
</html>