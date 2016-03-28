<?
require_once('init.php');

EasyRdf_Namespace::set('solid', 'http://www.w3.org/ns/solid/terms#');

function get_inbox($url){
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
  
  $normed = $graph->serialise('turtle');
  return $normed;
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
  
  $normed = $graph->serialise('turtle');
  return $normed;
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
  
  $normed = $graph->serialise('turtle');
  return $normed;
  
}

function write_notification($inbox, $turtle){
  
  $turtle = str_replace('placeholder', '', $turtle);
  
  $ch = curl_init();
  curl_setopt($ch,CURLOPT_URL, $inbox);
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
  curl_setopt($ch, CURLOPT_POSTFIELDS, $turtle);
  curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    'Content-Type: text/turtle',
    'Content-Length: ' . strlen($turtle))
  );
  $result = curl_exec($ch);
  curl_close($ch);
  return $result;
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
    if($write){
      $success = "Posted!";
      $inbox = $route['inbox'];
    }else{
      $errors['write'] = "Notification not posted.";
    }
  }else{
    $errors = array_merge($route['errors'], $notif['errors']);
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
      <p>Send Solid Notifications to any inbox.</p>
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
    <nav>
      <ul>
        <li><a href="#formAs" id="linkAs">ActivityStreams2</a></li>
        <li><a href="#formPingback" id="linkPingback">Pingback</a></li>
        <li><a href="#formSioc" id="linkSioc">Sioc</a></li>
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
    
    <footer>
      <ul>
        <li><a href="https://github.com/linkeddata/errol">Source</a></li>
        <li><a href="https://github.com/linkeddata/errol/issues">Issues</a></li>
        <li><a href="https://github.com/solid">Solid</a></li>
        <li><a href="https://github.com/solid/solid-spec#notifications">Solid Notifications</a></li>
      </ul>
    </footer>
    
    <script>
      if(window.location.hash == "#formPingback"){
        document.getElementById('formAs').style.display = 'none';
        document.getElementById('formSioc').style.display = 'none';
        document.getElementById('linkPingback').style.backgroundColor = 'silver';
      }else if(window.location.hash == "#formSioc"){
        document.getElementById('formAs').style.display = 'none';
        document.getElementById('formPingback').style.display = 'none';
        document.getElementById('linkSioc').style.backgroundColor = 'silver';
      }else{
        document.getElementById('formPingback').style.display = 'none';
        document.getElementById('formSioc').style.display = 'none';
        document.getElementById('linkAs').style.backgroundColor = 'silver';
      }
      document.getElementById('linkPingback').addEventListener('click', function(){
        this.style.backgroundColor = 'silver';
        document.getElementById('linkAs').style.backgroundColor = 'white';
        document.getElementById('linkSioc').style.backgroundColor = 'white';
        
        document.getElementById('formPingback').style.display = 'block';
        
        document.getElementById('formAs').style.display = 'none';
        document.getElementById('formSioc').style.display = 'none';
      });
      document.getElementById('linkAs').addEventListener('click', function(){
        this.style.backgroundColor = 'silver';
        document.getElementById('linkPingback').style.backgroundColor = 'white';
        document.getElementById('linkSioc').style.backgroundColor = 'white';
        
        document.getElementById('formAs').style.display = 'block';
        
        document.getElementById('formPingback').style.display = 'none';
        document.getElementById('formSioc').style.display = 'none';
      });
      document.getElementById('linkSioc').addEventListener('click', function(){
        
        this.style.backgroundColor = 'silver';
        document.getElementById('linkPingback').style.backgroundColor = 'white';
        document.getElementById('linkAs').style.backgroundColor = 'white';
        
        document.getElementById('formSioc').style.display = 'block';
        
        document.getElementById('formPingback').style.display = 'none';
        document.getElementById('formAs').style.display = 'none';
      });
    </script>
  </body>
</html>