<?php

$con = mysqli_connect("localhost", "root", "", "db_libraryms");


if(!$con){
    die("connection failed: " . mysqli_connect_error());
}
// else{
//     echo"connected ka pre!";
// }
?>