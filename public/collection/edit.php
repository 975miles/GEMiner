<?php
require_once $_SERVER['DOCUMENT_ROOT']."/../start.php";
require_auth();
require_once $_SERVER['DOCUMENT_ROOT']."/../consts/collection-types.php";
gen_top("GEMiner - Editing a collection...");
require_once $_SERVER['DOCUMENT_ROOT']."/../fn/get_collection.php";
require_once $_SERVER['DOCUMENT_ROOT']."/../fn/real_gem_amounts.php";

if ($collection['by'] != $user['id'])
    throw_error("This collection isn't yours.");

$gem_amounts = get_real_gem_amounts($exclude=$collection['id']);

if (isset($_POST['collection_data'])) {
    function parse_collection() {
        global $collection_types;
        global $collection;
        global $gem_amounts;
        global $user;
        global $dbh;
        $gem_amounts_parsing = $gem_amounts;
        $collection_data = json_decode($_POST['collection_data'], true);
        $collection_type = $collection_types[$collection['type']];
        if (gettype($collection_data) != "array")
            return;
        if (count($collection_data) != $collection_type->height)
            return;
        
        foreach($collection_data as $row) {
            if (gettype($row) != "array")
                return;
            if (count($row) != $collection_type->width)
                return;
            foreach($row as $tile) {
                if (gettype($tile) != "integer")
                    return;
                if (isset($gem_amounts_parsing[$tile])) {
                    if ($gem_amounts_parsing[$tile] < 1000)
                        return;
                    $gem_amounts_parsing[$tile] -= 1000;
                } else if ($tile != -1)
                    return;
            }
        }
        $dbh->prepare("UPDATE collections SET data = ? WHERE id = ?;")
            ->execute([json_encode($collection_data), $collection['id']]);
        redirect("/collection/view.php?id=".dechex($collection['id']));
    }

    parse_collection();
    echo "aaa";
}
?>

<h1>wip</h1>
<p>Right click on any tile to show what gem it is.</p>
<canvas id="collectionEditor" style="width:100%;"></canvas>
<div id="gems"></div>
<hr>
<button class="btn btn-lg btn-secondary" onclick="submit()">Finish</button>

<script>
    var boxWidth = 6;
    var gridLineWidth = 1;
    var hoverTime = 1000;
    var boxWidthFull = boxWidth + gridLineWidth;
    var collectionData = JSON.parse("<?=$collection['data']?>");
    var gemAmounts = Object.assign({}, JSON.parse("<?=json_encode($gem_amounts)?>"));
    gemAmounts["-1"] = Infinity;
    var collectionWidth = collectionData[0].length;
    var collectionHeight = collectionData.length;
    var pixelWidth = (collectionWidth * boxWidth) + ((collectionWidth - 1) * gridLineWidth);
    var pixelHeight = (collectionHeight * boxWidth) + ((collectionHeight - 1) * gridLineWidth);
    var canvasObject = $("#collectionEditor");
    var canvasWidth = canvasObject.width();
    var canvasHeight = canvasWidth * (collectionHeight / collectionWidth);
    canvasObject.removeAttr("style");
    canvasObject.attr("width", canvasWidth);
    canvasObject.attr("height", canvasHeight);
    var canvas = canvasObject[0];
    var context = canvas.getContext("2d");
    var submitting = false;
    var mousePos;
    var mouseDown = false;
    var mouseOnTile = false;
    var gemsInfo;
    var selectedGem = null;
    var lastMouseMove = Date.now();

    //#ffdc7d is empty btw

    function convertCoords(x, y) {
        return {
            x: x / (canvasWidth / pixelWidth),
            y: y / (canvasHeight / pixelHeight)
        };
    }

    function reverseConvertCoords(x, y) {
        return {
            x: Math.round(x * (canvasWidth / pixelWidth)),
            y: Math.round(y * (canvasHeight / pixelHeight))
        };
    }

    var realBoxWidth = reverseConvertCoords(boxWidth, 0).x;

    canvas.addEventListener('mousemove', evt => {
        let rect = canvas.getBoundingClientRect();
        let currentMousePos = convertCoords(evt.clientX - rect.left, evt.clientY - rect.top);
        if ((Math.floor(currentMousePos.x) % boxWidthFull > boxWidthFull - 1 - gridLineWidth) || (Math.floor(currentMousePos.y) % boxWidthFull > boxWidthFull - 1 - gridLineWidth))
            mouseOnTile = false;
        else {
            mouseOnTile = true;
            let newMousePos = {
                x: (Math.floor(currentMousePos.x) - (Math.floor(currentMousePos.x) % boxWidthFull)) / boxWidthFull,
                y: (Math.floor(currentMousePos.y) - (Math.floor(currentMousePos.y) % boxWidthFull)) / boxWidthFull
            };
            if (JSON.stringify(mousePos) != JSON.stringify(newMousePos))
                lastMouseMove = Date.now();
            mousePos = newMousePos;
            if (mouseDown)
                placeGem();
        }
    });

    canvas.addEventListener('mousedown', () => {
        mouseDown = true;
        placeGem();
    });
    document.addEventListener('mouseup', () => mouseDown = false);

    function placeGem() {
        if (mouseOnTile && selectedGem != null) {
            gemRemoving = collectionData[mousePos.y][mousePos.x];
            gemRemovingAmount = $(`#gem_${gemRemoving}_amount`);
            gemRemovingAmount.html((Number(gemRemovingAmount.html()) + 1).toFixed(3));
            gemPlacingAmount = $(`#gem_${selectedGem}_amount`);
            gemPlacingAmount.html((Number(gemPlacingAmount.html()) - 1).toFixed(3));

            collectionData[mousePos.y][mousePos.x] = selectedGem;

            context.fillStyle = "#"+gemsInfo[selectedGem].colour;

            let tileCoords = reverseConvertCoords(mousePos.x * boxWidthFull, mousePos.y * boxWidthFull);
            context.fillRect(tileCoords.x, tileCoords.y, realBoxWidth, realBoxWidth);

            correctAvailabilityClass(gemRemoving);
            correctAvailabilityClass(selectedGem);
        }
    }

    canvas.addEventListener('contextmenu', evt => {
        if (mouseOnTile) {
            evt.preventDefault();
            let tile = collectionData[mousePos.y][mousePos.x];
            let tileName;
            if (tile == -1)
                tileName = "empty";
            else
                tileName = gemsInfo[tile].name;
            showInfo("", tileName);
        }
    });

    window.addEventListener("beforeunload", function (e) {
        if (submitting)
            return null;

        var confirmationMessage = "If you leave this page, all edits to your collection will be lost!";

        (e || window.event).returnValue = confirmationMessage;
        return confirmationMessage;
    });

    function drawGrid() {
        context.fillStyle = "black";
        /*
        realGridLineWidth = reverseConvertCoords(gridLineWidth, 0).x;
        for (let row = 1; row < collectionHeight; row++)
            context.fillRect(0, reverseConvertCoords(0, row * boxWidthFull - gridLineWidth).y, canvasWidth, realGridLineWidth);
        
        for (let column = 1; column < collectionWidth; column++)
            context.fillRect(reverseConvertCoords(column * boxWidthFull - gridLineWidth, 0).x, 0, realGridLineWidth, canvasHeight);
        */
        context.fillRect(0, 0, canvasWidth, canvasHeight);
    }

    function drawTiles() {
        for (let row = 0; row < collectionHeight; row++)
            for (let column = 0; column < collectionWidth; column++) {
                let tile = collectionData[row][column];
                context.fillStyle = "#"+gemsInfo[tile].colour;

                let tileCoords = reverseConvertCoords(column * boxWidthFull, row * boxWidthFull)
                context.fillRect(tileCoords.x, tileCoords.y, realBoxWidth, realBoxWidth);

                gemAmounts[tile] -= 1000;
            }
    }

    function createButtons() {
        $("#gems").html("");
        for (i of Object.keys(gemAmounts).sort()) { //sort is just to get emptite to the top
            $("#gems").append($(`<div class="colour-displayer" style="background:#${gemsInfo[i].colour}"/>`))
            $("#gems").append($(`<button id="gem_${gemsInfo[i].id}_button" class="btn btn-primary" onclick="selectGem(${gemsInfo[i].id})">${gemsInfo[i].name}: <span id="gem_${gemsInfo[i].id}_amount">${(gemAmounts[i]/1000).toFixed(3)}</span><span id="gem_${gemsInfo[i].id}_unit">P</button>"`));
            correctAvailabilityClass(gemsInfo[i].id);
            $("#gems").append($("<br>"));
        }
    }

    function correctAvailabilityClass(gem) {
        let gem_button = $(`#gem_${gem}_button`);
        let gem_amount = Number($(`#gem_${gem}_amount`).html());

        if (gem_amount >= 1 && gem_button.hasClass("disabled"))
            gem_button.removeClass("disabled");

        else if (gem_amount < 1 && !gem_button.hasClass("disabled")) {
            if (selectedGem == gem)
                selectGem(gem);
            gem_button.addClass("disabled");
        }
    }

    function selectGem(gem) {
        let gem_button = $(`#gem_${gem}_button`);

        if (gem_button.hasClass("disabled"))
            return;

        $(".btn.active").removeClass("active");

        if (selectedGem == gem) {
            selectedGem = null;
            return;
        }
        gem_button.addClass("active");
        selectedGem = gem;
    }

    function submit() {
        submitting = true;
        let form = $(`<form action="" method="post"><input name="collection_data" value="${JSON.stringify(collectionData)}" /></form>`);
        $("body").append(form);
        form.submit();
    }

    async function drawCollection() {
        drawGrid();
        gemsInfo = await loadGems();
        drawTiles();
        createButtons();
    }
    drawCollection();
</script>

<?php gen_bottom(); ?>