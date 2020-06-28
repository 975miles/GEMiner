<?php
require_once $_SERVER['DOCUMENT_ROOT']."/../start.php";
require_once $_SERVER['DOCUMENT_ROOT']."/../consts/collection-types.php";
require_auth();
gen_top("Geminer premium", "Redeem a Geminer premium code.");

if ($user['is_premium']) {
    ?>
    <h3>You're already a premium member, you can't redeem another code.</h3>
    <?php
    gen_bottom();
}

if (isset($_POST) and isset($_POST['code'])) {
    $code_hash = hash("sha512", $_POST['code']);
    $sth = $dbh->prepare("SELECT * FROM codes WHERE code_hash = ?");
    $sth->execute([$code_hash]);
    $results = $sth->fetchAll(PDO::FETCH_ASSOC);
    if (count($results) > 0) {
        $dbh->prepare("UPDATE users SET is_premium = 1, date_became_premium = ? WHERE id = ?")->execute([time(), $user['id']]);
        $massive_collection_data = Array();
        for ($row_num = 0; $row_num < $collection_types[3]->height; $row_num++) {
            $row = Array();
            for ($column_num = 0; $column_num < $collection_types[3]->width; $column_num++)
                $row[$column_num] = -1;
            $massive_collection_data[$row_num] = $row;
        }
        $dbh->prepare("INSERT INTO collections (type, by, created_at, data, name) VALUES (?, ?, ?, ?, ?)")->execute([3, $user['id'], time(), json_encode($massive_collection_data), $user['name']."'s Bigmassive Collection"]);
        $dbh->prepare("DELETE FROM codes WHERE code_hash = ?")->execute([$code_hash]);
        redirect("/premium/welcome.php");
    } else
        show_info("Your redemption code was invalid.", "Unknown code");
}
?>

<h1>Redeem</h1>
<p>Don't have a code? Read about getting one <a class="btn btn-sm btn-primary" href="/premium">here</a>.</p>
<form action="" method="post" autocomplete="off">
    <input type="text" name="code" class="form-control" placeholder="Enter your premium code here" value="<?php if (isset($_GET['code'])) echo $_GET['code']; ?>">
    <button type="submit" class="btn btn-lg btn-primary">Submit</button>
</form>

<?php gen_bottom(); ?>