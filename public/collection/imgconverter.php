<?php
require_once $_SERVER['DOCUMENT_ROOT']."/../start.php";
require_once $_SERVER['DOCUMENT_ROOT']."/../fn/real_gem_amounts.php";
gen_top("Convert an image to a collection");

$reached_maximum = true;
if ($is_logged_in) {
    $max_collection_amount = ($user['is_premium'] ? $collection_storage_limit_premium : $collection_storage_limit_free);

    $sth = $dbh->prepare("SELECT COUNT(1) FROM collections WHERE by = ?");
    $sth->execute([$user['id']]);
    $collection_amount = $sth->fetchColumn();

    if ($collection_amount < $max_collection_amount)
        $reached_maximum = false;
}
?>

<h1>Image converter</h1>
<div class="form-inline">
    <label>Select a collection size:&nbsp</label>
    <select class="form-control" id="sizeSelect"></select>
</div>
<label>
    <input class="d-none" type="file" onchange="uploadImage(this)">
    <span class="btn btn-primary">Choose image...</span>
</label>
<hr>
<div id="generatedCollection">
    <p>Upload an image and see what it would look like as a collection and what gems you'd need to make it.</p>
</div>

<script>
function colourDifference(r1, g1, b1, r2, g2, b2) {
    var sumOfSquares = 0;

    sumOfSquares += Math.pow(r1 - r2, 2);
    sumOfSquares += Math.pow(g1 - g2, 2);
    sumOfSquares += Math.pow(b1 - b2, 2);
    
    return Math.sqrt(sumOfSquares);
}

async function drawCollection() {
    $("#drawModeSwitcher").html("Switch to "+(mode == "gem" ? "colour" : "gem")+" rendering mode");
    let outputCanvas = $('<canvas id="outputImage" class="collection-img fill-page">')[0];
    outputCanvas.height = tileSize * collectionSize.height;
    outputCanvas.width = tileSize * collectionSize.width;
    let outputCanvasCtx = outputCanvas.getContext("2d");

    for (let y=0; y<collectionSize.height; y++) {
        for (let x=0; x<collectionSize.width; x++) {
            let gem = gemsInfo[outputImage[y][x]];

            new Promise(async (res, rej) => {
                if (mode == "colour") {
                    outputCanvasCtx.fillStyle = "#"+gem.colour;
                    outputCanvasCtx.fillRect(x * tileSize, y * tileSize, tileSize, tileSize);
                    res();
                } else if (mode == "gem") {
                    let gemImage = new Image();
                    gemImage.onload = () => {
                        outputCanvasCtx.drawImage(gemImage, x * tileSize, y * tileSize, tileSize, tileSize);
                        res();
                    }
                    gemImage.src = `/a/i/gem/${gem.id}.png`;
                }
            });
        }
    }

    outputCanvas.addEventListener('mousemove', async evt => {
        let rect = outputCanvas.getBoundingClientRect();
        let currentMousePos = {
            x: evt.clientX - rect.left,
            y: evt.clientY - rect.top
        };
        $outputCanvas = $(outputCanvas);
        let canvasWidth = $outputCanvas.width();
        let canvasHeight = $outputCanvas.height();
        let tileWidth = canvasWidth / collectionSize.width;
        let gem = gemsInfo[outputImage[(Math.floor(currentMousePos.y) - (Math.floor(currentMousePos.y) % tileWidth)) / tileWidth][(Math.floor(currentMousePos.x) - (Math.floor(currentMousePos.x) % tileWidth)) / tileWidth]];
        $("#gemHover").html("That gem is "+await displayGem(gem.id)+gem.name+".");
    });

    return outputCanvas;
}

async function switchDrawMode() {
    mode = (mode == "gem" ? "colour" : "gem");
    $("#outputImage").replaceWith($(await drawCollection()));
}

function uploadImage(inputElement) {
    if (inputElement.files && inputElement.files[0]) {
        let reader = new FileReader();

        reader.onload = e => {
            let img = new Image();
            img.onload = () => {
                collectionType = Number($("#sizeSelect").val());
                collectionSize = collectionTypes[collectionType];
                let canvas = $("<canvas>")[0];
                canvas.width = collectionSize.width;
                canvas.height = collectionSize.height;
                let ctx = canvas.getContext("2d");
                ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
                generateImage(canvas);
            }
            img.src = e.target.result;
        };

        reader.readAsDataURL(inputElement.files[0]);
    }
}

async function generateImage(sourceImg) {
    await gemColours;
    let sourceImgCtx = sourceImg.getContext("2d");
    let pixels = sourceImgCtx.getImageData(0, 0, sourceImg.width, sourceImg.height).data;
    let generatedCollection = [];
    for (let i = 0; i < pixels.length; i += 4) {
        let r1 = pixels[i];
        let r2 = pixels[i+1];
        let r3 = pixels[i+2];

        let closestGem;
        let lowestDifference = Infinity;
        for (let gem in gemColours) {
            let colour = gemColours[gem];
            let difference = colourDifference(r1, r2, r3, colour.r, colour.g, colour.b);
            if (difference < lowestDifference) {
                closestGem = gem;
                lowestDifference = difference;
            }
        }
        generatedCollection.push(closestGem);
    }

    outputImage = [];
    for (let i = 0; i < generatedCollection.length; i += collectionSize.width) {
        let row = [];
        for (k = 0; k < collectionSize.width; k++) {
            row.push(generatedCollection[i+k]);
        }
        outputImage.push(row);
    }
    //await genCollectionImage(JSON.stringify(outputImage));
    let div = $("#generatedCollection");
    div.html("");
    let images = {"Input": sourceImg.toDataURL(), "Output": await genCollectionImage(JSON.stringify(outputImage))};
    div.append($(`<div><label>Input:</label><br><img src="${sourceImg.toDataURL()}" class="collection-img fill-page"><br></div>`));
    div.append($("<div><label>Output:</label><br></div>"));
    div.append(await drawCollection());
    div.append($('<div><br><p id="gemHover"></div>'));
    div.append($('<button class="btn btn-primary" id="drawModeSwitcher" onclick="switchDrawMode()">Switch to '+(mode == "gem" ? "colour" : "gem")+' rendering mode</button>'));

    let gemAmounts = {};
    for (let i of generatedCollection) {
        if (gemAmounts[i] != null)
            gemAmounts[i]++;
        else
            gemAmounts[i] = 1;
    }

    let gemsNeeded = $("<p>You will need: <br><br></p>");
    let creatable = !reachedMaximum;
    for (i in gemAmounts) {
        gemsNeeded.append(`${gemAmounts[i]}px of ${await displayGem(i)}${gemsInfo[i].name}`);
        if (loggedIn) {
            let userGemAmount = userGemAmounts[i]/1000;
            gemsNeeded.append(` - You have ${userGemAmount}px. ${(userGemAmount < gemAmounts[i] ? `You need ${gemAmounts[i]-userGemAmount}px more.` : "You have enough!")}`);
            if (creatable && userGemAmount < gemAmounts[i])
                creatable = false;
        }
        gemsNeeded.append('<br>')
    }
    div.append($("<hr>"));
    div.append(gemsNeeded);
    if (creatable)
        div.append($('<button class="btn btn-primary" onclick="createCollection()">Create as a collection</button>'));
    else {
        let err;
        if (loggedIn) {
            if (reachedMaximum)
                err = "You've reached your maximum amount of collections.";
            else
                err = "You're missing some gems.";
        } else
            err = "You need to be logged in to create collections."
        let btn = $('<button class="btn btn-primary" data-toggle="tooltip" title="'+err+'" disabled>Create as a collection</button>');
        div.append(btn);
        btn.tooltip();
    }
}

function createCollection() {
    var collectionName = "Generated collection"
    $.post("/collection/create", {
        name: collectionName,
        type: collectionType
    }, page => {
        eval($(page).find("#redirect").html().split("\n")[1]);
        console.log(redirectURL);
        for (let i in outputImage)
            for (let t in outputImage[i])
                outputImage[i][t] = Number(outputImage[i][t]);
        $.post(redirectURL, {
            name: collectionName,
            collection_data: JSON.stringify(outputImage),
            mode: mode
        }, page2 => {
            console.log(JSON.stringify(outputImage));
            console.log(page2)
            console.log($(page2).find("#redirect").html())
            eval($(page2).find("#redirect").html());
        });
    });
}

const tileSize = 16;
var outputImage;
var collectionSize;
var collectionType;
var reachedMaximum = <?=$reached_maximum ? "true" : "false"?>;
var mode = "colour";
var gemColours = new Promise(async (res, rej) => {
    await gemsInfo;
    let tempGemColours = [];
    for (let gem of gemsInfo) {
        tempGemColours.push(hexToRgb("#"+gem.colour));
    }
    gemColours = tempGemColours;
    res();
});

<?php if ($is_logged_in) { ?>
var userGemAmounts = JSON.parse("<?=json_encode(get_real_gem_amounts())?>");
<?php } ?>

var collectionTypes;

$.getJSON("/a/data/collection-types.json", data=>{
    collectionTypes = data;
    for (i in collectionTypes) {
        let type = collectionTypes[i];
        if (!(type.premium && (!loggedIn || !user.is_premium)))
            $("#sizeSelect").append($(`<option value="${i}">${type.name} - ${type.width}px*${type.height}px</option>`));
    }
});
</script>

<?php gen_bottom(); ?>