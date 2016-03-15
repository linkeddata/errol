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
    var_dump($data);
    var_dump($e);
    return $e->getMessage();
  }
  
  $subject = $graph->resource($url);
	$inbox = $subject->get('solid:inbox');

	return $inbox;
}

function make_notification_as($post){
  
  $graph = new EasyRdf_Graph();
  EasyRdf_Namespace::set('as', 'http://w3.org/ns/activitystreams#');
  
  $notif = $graph->resource('placeholder', 'solid:Notification');
  
  if($post['source']){
    $graph->add($notif, 'rdf:type', $graph->resource('as:Announce'));
    $graph->add($notif, 'as:object', $graph->resource($post['source']));
    if($post['object'] != ""){
      $graph->add($notif, 'as:target', $graph->resource($post['object']));
      $graph->add($notif, 'as:object', $graph->resource($post['object']));
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
  
  $normed = $graph->serialise('turtle');
  return $normed;
}

function make_notification_pingback($post){
  
  $graph = new EasyRdf_Graph();
  EasyRdf_Namespace::set('pingback', 'http://purl.org/net/pingback/');
  
  $notif = $graph->resource(' ', 'solid:Notification');
  $graph->add($notif, 'rdf:type', $graph->resource('pingback:Request'));
  $graph->add($notif, 'pingback:source', $graph->resource($post['source']));
  $graph->add($notif, 'pingback:target', $graph->resource($post['object']));
  
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

if(isset($_POST)){
  $errors = array();
  
  if(isset($_POST['sendAs'])){
    
    if($_POST['to'] == "" && $_POST['inReplyTo'] == "" && $_POST['object'] == ""){
      $errors['to'] = "Must include one or more of to or in reply to or about.";
    }
    if($_POST['source'] == "" && $_POST['content'] == ""){
      $errors['source'] = "Must include source and/or content";
    }
    if(count($errors) < 1){
      $notification = make_notification_as($_POST);
    }
  
  }elseif(isset($_POST['sendPingback'])){
    
    if($_POST['source'] == "" || $_POST['object'] == ""){
      $errors['pingback'] = "Must include source and target";
    }
    if(count($errors) < 1){
      $notification = make_notification_pingback($_POST);
    }
    
  }
  
  if($_POST['to'] != ""){
    $to = $_POST['to'];
  }elseif($_POST['object'] != ""){
    $to = $_POST['object'];
  }elseif($_POST['inReplyTo'] != ""){
    $to = $_POST['inReplyTo'];
  }
  if(isset($to)){
    $inbox = get_inbox($to);
    if(!isset($inbox)){
      $errors['inbox'] = "No inbox found for $to :(";
    }elseif(isset($notification)){
      if(write_notification($inbox, $notification)){
        $success = "Posted!";
      }
    }else{
      echo "no notification";
    }
  }
    
}
?>
<!doctype html>
<html>
  <head>
    <title>Errol</title>
    <style type="text/css">
      body { font-family: Arial, sans-serif; }
      header { width: 60%; margin-right: auto; margin-left: auto; font-family: serif; font-size: 1.6em; color: gray; text-align: center;  }
      h1 { margin: 0; font-size: 2.4em; }
      nav { width: 60%; margin-right: auto; margin-left: auto; font-size: 1em; clear: both; overflow: hidden; }
      nav ul { list-style-type: none; padding: 0; margin: 0; }
      nav li { float: left; }
      nav li a { display: inline-block; padding: 0.2em; text-decoration: none; color: black; border: 1px solid silver; }
      nav li a:hover { text-decoration: none; background-color: silver; }
      form { width: 60%; margin-right: auto; margin-left: auto; border-top: 1px solid silver; }
      form label { display: inline-block; }
      form input, form textarea { width: 100%; padding: 0.4em; background-color: white; border: 1px solid silver; }
      .error { font-weight: bold; color: red; }
      .success { font-weight: bold; color: green; }
      input[type="submit"] { background-color: silver; padding: 0.6em; }
      div { width: 60%; margin-left: auto; margin-right: auto; }
      pre { border: 1px solid silver; }
    </style>
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
    <?if(isset($success)):?>
      <div class="success">
        <p><?=$success?></p>
      </div>
    <?endif?>
    <nav>
      <ul>
        <li><a href="#formAs" id="linkAs">ActivityStreams2</a></li>
        <li><a href="#formPingback" id="linkPingback">Pingback</a></li>
      </ul>
    </nav>
    <form method="post" id="formAs">
      <h2>ActivityStreams2</h2>
      <p>All fields are optional, but you must include either <em>to</em> (person) or <em>in reply to</em> or <em>object</em> (resource), and you must include either <em>content</em> or a <em>source</em>.</p>
      <?=isset($errors['to']) ? '<div class="error"><p>'.$errors['to'].'</p>' : ""?>
        <p><label for="to">To</label> <input name="to" type="url" placeholder="WebID of a person" value="<?=isset($_POST['to']) ? $_POST['to'] : ''?>"/></p>
        <p><label for="inReplyTo">In reply to</label> <input name="inReplyTo" type="url" placeholder="URI of a resource that this notification is in reply to" value="<?=isset($_POST['inReplyTo']) ? $_POST['inReplyTo'] : ''?>" /></p>
        <p><label for="object">Object</label> <input name="object" type="url" placeholder="URI of a resource that this notification is about (but not a reply)" value="<?=isset($_POST['object']) ? $_POST['object'] : ''?>" /></p>
      <?=isset($errors['to']) ? '</div>' : ""?>
      <?=isset($errors['source']) ? '<div class="error"><p>'.$errors['source'].'</p>' : ""?>
        <p><label for="source">Source</label> <input name="source" type="url" placeholder="URI of a resource with additional relevent information" value="<?=isset($_POST['source']) ? $_POST['source'] : ''?>" /></p>
        <p><label>Content</label> <textarea name="content"><?=isset($_POST['content']) ? $_POST['content'] : ''?></textarea></p>
      <?=isset($errors['source']) ? '</div>' : ""?>
      <p><label>Your URI</label> <input name="actor" type="url" value="<?=isset($_POST['actor']) ? $_POST['actor'] : ''?>" /></p>
      <p><input type="submit" id="sendAs" name="sendAs" value="Send" /></p>
    </form>
    <form method="post" id="formPingback">
      <h2>Pingback</h2>
      <p><em>Source</em> and <em>target</em> are required.</p>
      <?=isset($errors['pingback']) ? '<div class="error"><p>'.$errors['pingback'].'</p>' : ""?>
        <p><label>Source</label> <input name="source" type="url" placeholder="Where this notification is coming from" value="<?=isset($_POST['source']) ? $_POST['source'] : ''?>" /></p>
        <p><label>Target</label> <input name="object" type="url" placeholder="Where this notification is pointing to" value="<?=isset($_POST['object']) ? $_POST['object'] : ''?>" /></p>
        <p><input type="submit" id="sendPingback" name="sendPingback" value="Send" /></p>
    </form>
    <script>
      if(window.location.hash == "#formPingback"){
        document.getElementById('formAs').style.display = 'none';
        document.getElementById('linkPingback').style.backgroundColor = 'silver';
      }else{
        document.getElementById('formPingback').style.display = 'none';
        document.getElementById('linkAs').style.backgroundColor = 'silver';
      }
      document.getElementById('linkPingback').addEventListener('click', function(){
        this.style.backgroundColor = 'silver';
        document.getElementById('linkAs').style.backgroundColor = 'white';
        document.getElementById('formPingback').style.display = 'block';
        document.getElementById('formAs').style.display = 'none';
      });
      document.getElementById('linkAs').addEventListener('click', function(){
        this.style.backgroundColor = 'silver';
        document.getElementById('linkPingback').style.backgroundColor = 'white';
        document.getElementById('formAs').style.display = 'block';
        document.getElementById('formPingback').style.display = 'none';
      });
    </script>
  </body>
</html>