import React from "react";
import { Accordion, AccordionSummary, AccordionDetails } from "@mui/material";
import ExpandMoreIcon from "@mui/icons-material/ExpandMore";
import { styled } from "@mui/system";

const customAccordionStyles = {
  fontFamily: "'Century Gothic', sans-serif",
  backgroundColor: "#111",
  border: "none",
  boxShadow: "none",
};

const customAccordionSummaryStyles = {
  backgroundColor: "#111",
  color: "#ccc",
  cursor: "pointer",
  width: "100%",
};

const customAccordionDetailsStyles = {
  backgroundColor: "#111",
  color: "#ccc",
};

const customExpandMoreIconStyles = {
  color: "#ccc",
};

const CustomAccordion = styled(Accordion)(customAccordionStyles);

const CustomAccordionSummary = styled(AccordionSummary)(
  customAccordionSummaryStyles
);

const CustomAccordionDetails = styled(AccordionDetails)(
  customAccordionDetailsStyles
);

const CustomExpandMoreIcon = styled(ExpandMoreIcon)(customExpandMoreIconStyles);

export {
  CustomAccordion,
  CustomAccordionSummary,
  CustomAccordionDetails,
  CustomExpandMoreIcon,
};
