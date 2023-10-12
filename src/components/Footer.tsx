import React from "react";
import logo from "../assets/tdp_logo_blue.svg";
import AboutPage from "../pages/About";
import GetInvolved from "../pages/GetInvolved";
import PopupWindow from "./PopupWindow";
import QRCode from "react-qr-code";
import { graphqlUrl } from "../data/graphqlConfig";

const Footer = () => {
  return (
    <footer className="footer">
      {/* <div className="nav">
        <ul>
          <li>
            <PopupWindow
              triggerElement={<a href="#">About</a>}
              content={<AboutPage />}
            />
          </li>
          <li>
            <PopupWindow
              triggerElement={<a href="#">Get Involved</a>}
              content={<GetInvolved />}
            />
          </li>
        </ul>
      </div> */}
      <div>
        <a href={graphqlUrl} target="_blank" rel="noreferrer">
          <img
            className="logo"
            src={logo}
            alt="Deprogrammer"
            title="Deprogrammer"
          />
        </a>
      </div>
      <div className="qr-code">
        <QRCode value={graphqlUrl} size={100} />
      </div>
    </footer>
  );
};

export default Footer;
