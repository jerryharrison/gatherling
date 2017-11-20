<?php
require_once('../lib.php');

if (PHP_SAPI != "cli"){
  session_start();
  if (!Player::isLoggedIn() || !Player::getSessionPlayer()->isSuper()) {
    redirect("index.php");
  }
}

updateStandard();
updateModern();
updatePennyDreadful();

function info($text){
  if (PHP_SAPI == "cli"){
    echo $text . "\n";
  }
  else{
    echo $text . "<br/>";
  }
}

function addSet($set) {
  if (PHP_SAPI == "cli") {
    info("Please add {$set} to the database");
  }
  else
  {
    redirect("insertcardset.php?cardsetcode={$set}&return=updateDefaultFormats.php");
  }
}

function LoadFormat($format){
  if (!Format::doesFormatExist($format))
  {
    $active_format = new Format("");
    $active_format->name = $format;
    $active_format->type = "System";
    $active_format->series_name = "System";
    $success = $active_format->save();
  }
  return new Format($format);
}

function updateStandard(){
  $fmt = LoadFormat("Standard");
  $legal = json_decode(file_get_contents("http://whatsinstandard.com/api/v5/sets.json"));
  if (!$legal)
  {
    info("Unable to load WhatsInStandard API.  Aborting.");
    return;
  }
  $expected = array();
  foreach ($legal->sets as $set){
    $enter = strtotime($set->enter_date);
    $exit = strtotime($set->exit_date);
    $now = time();
    if ($exit == NULL)
      $exit = $now + 1;
    if ($exit < $now)
    {
      // Set has rotated out.
    }
    else if ($enter > $now)
    {
      // Set is yet to be released. (And probably not available in MTGJSON yet)
    }
    else
    {
      // The ones we care about.
      $db = Database::getConnection();
      $stmt = $db->prepare("SELECT name, type FROM cardsets WHERE code = ?");
      $stmt->bind_param("s", $set->code);
      $stmt->execute();
      $stmt->bind_result($setName, $setType);
      $success = $stmt->fetch();
      $stmt->close();
      if (!$success){
        addSet($set->code);
        return;
      }
      $expected[] = $setName;
    }
  }
  foreach ($fmt->getLegalCardsets() as $setName) {
    $remove = true;
    foreach ($expected as $legalsetName) {
      if (strcmp($setName, $legalsetName) == 0) {  
        $remove = false;
      }
    }
    if ($remove){
      $fmt->deleteLegalCardSet($setName);
      info("{$setName} is no longer Standard Legal.");      
    }
  }

  foreach ($expected as $setName){
    if (!$fmt->isCardSetLegal($setName)) {
      $fmt->insertNewLegalSet($setName);
      info("{$setName} is now Standard Legal.");
    }
  }
}

function updateModern(){
  $fmt = LoadFormat("Modern");
  
  $legal = $fmt->getLegalCardsets();

  $db = Database::getConnection();
  $stmt = $db->prepare("SELECT name, type, released FROM cardsets WHERE `type` != 'extra' ORDER BY `cardsets`.`released` ASC");
  $stmt->execute();
  $stmt->bind_result($setName, $setType, $setDate);
  
  $sets = array();
  while ($stmt->fetch()) {
    $sets[] = array($setName, $setType, $setDate);
  }
  $stmt->close();

  $cutoff = strtotime("2003-07-27");
  foreach ($sets as $set)
  {
    $setName = $set[0];
    $release = strtotime($set[2]);
    if ($release > $cutoff)
    {
      if (!$fmt->isCardSetLegal($setName)) {
        $fmt->insertNewLegalSet($setName);
        info("{$setName} is Modern Legal.");
      }
    }
  }
}

function updatePennyDreadful()
{
  $fmt = LoadFormat("Penny Dreadful");

  $legal_cards = parseCards(file_get_contents("http://pdmtgo.com/legal_cards.txt"));
  if (!$legal_cards){
    info("Unable to fetch legal_cards.txt");
    return;
  }
  foreach ($fmt->card_legallist as $card) {
    $remove = true;
    foreach ($legal_cards as $legal_card) {
      if (strcasecmp($card, $legal_card) == 0) {  
        $remove = false;
      }
    }
    if ($remove){
      $fmt->deleteCardFromLegallist($card);
      info("{$card} is no longer PD Legal.");      
    }
  }
  foreach ($legal_cards as $card) {
    $success = $fmt->insertCardIntoLegallist($card);
    if(!$success) {
      info("Can't add {$card} to PD Legal list, it is not in the database.");
      $set = findSetForCard($card);
      addSet($set);
      return; 
    }
  }
}

function findSetForCard($card) {
  $card = urlencode($card);
  $data = json_decode(file_get_contents("http://api.scryfall.com/cards/named?exact={$card}"));
  return strtoupper($data->set);
}